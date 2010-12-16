<?php
/**
 * @package midgardmvc_helper_attachmentserver
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Various helper methods
 *
 * @package midgardmvc_helper_attachmentserver
 */
class midgardmvc_helper_attachmentserver_helpers
{

    /**
     * Gets a named variant of parent attachment
     *
     * @param mixed $parent either midgard_attachment (or subclass thereof) object or guid
     * @param string $variant the variant name
     * @param boolean $generate_if_missing generate the variant if it does not exist
     * @return object or boolean false
     */
    public static function get_variant($parent, string $variant, $generate_if_missing = false)
    {
        $qb = midgard_attachment::new_query_builder();
        $qb->add_constraint('name', '=', $variant);
        if (is_object($parent))
        {
            $qb->add_constraint('parentguid', '=', $parent->guid);
        }
        else
        {
            $qb->add_constraint('parentguid', '=', $parent);
        }
        $qb->set_limit(1);
        $variants = $qb->execute();
        unset($qb);
        if (empty($variants))
        {
            if (!$generate_if_missing)
            {
                return false;
            }
            return midgardmvc_helper_attachmentserver_helpers::generate_variant($parent, $variant);
        }
        return $variants[0];
    }

    /**
     * Gets a freshness-checked named variant of parent attachment
     *
     * @param mixed $parent either midgard_attachment (or subclass thereof) object or guid
     * @param string $variant the variant name
     * @param boolean $generate_if_missing generate the variant if it does not exist
     * @return object or boolean false
     * @see midgardmvc_helper_attachmentserver_helpers::get_variant()
     * @see midgardmvc_helper_attachmentserver_helpers::variant_is_fresh()
     */
    public static function get_fresh_variant($parent, string $variant_name, $generate_if_missing = false)
    {
        $variant_obj = midgardmvc_helper_attachmentserver_helpers::get_variant($parent, $variant_name, $generate_if_missing);
        if (!$variant_obj)
        {
            return false;
        }
        if (midgardmvc_helper_attachmentserver_helpers::variant_is_fresh($variant_obj, $parent))
        {
            return $variant_obj;
        }
        unset($variant_obj);
        return midgardmvc_helper_attachmentserver_helpers::generate_variant($parent, $variant_name, true);
    }

    /**
     * Gets a named variant of parent attachment
     *
     * @param object $variant object returned by midgardmvc_helper_attachmentserver_helpers::get_variant
     * @param object $parent parent of the variant object (or null)
     * @return boolean indicating state
     * @see midgardmvc_helper_attachmentserver_helpers::get_variant()
     */
    public static function variant_is_fresh(midgard_attachment $variant, $parent=null)
    {
        if (!is_object($parent))
        {
            $parent = new midgard_attachment($variant->parentguid);
        }
        if ($variant->metadata->revised > $parent->metadata->revised)
        {
            return true;
        }
        return false;
    }

    /**
     * Gets a named variant of parent attachment
     *
     * @param object $parent midgard_attachment (or subclass thereof) object
     * @param string $variant the variant name
     * @param boolean $force_regenerate renegeare variant instead of throwing error for existing variant
     * @return object (or will throw exception)
     */
    public static function generate_variant(midgard_attachment $parent, $variant, $force_regenerate = false)
    {
        $old_variant = midgardmvc_helper_attachmentserver_helpers::get_variant($parent, $variant);
        if ($old_variant !== false)
        {
            if (!$force_regenerate)
            {
                throw new midgardmvc_exception("Variant {$variant} already exists");
            }
            midgardmvc_core::get_instance()->authorization->enter_sudo('midgardmvc_helper_attachmentserver');
            $old_variant->delete();
            midgardmvc_core::get_instance()->authorization->leave_sudo();
            unset($old_variant);
        }

        // Bergies code from midgardmvc_helper_attachmentserver_controllers_variant
        midgardmvc_core::get_instance()->component->load_library('ImageConverter');

        $settings = new ezcImageConverterSettings
        (
            array
            (
                new ezcImageHandlerSettings( 'ImageMagick', 'ezcImageImagemagickHandler' ),
            )
        );
        $converter = new ezcImageConverter($settings);

        $variants = midgardmvc_core::get_instance()->configuration->attachmentserver_variants;
        if (!isset($variants[$variant]))
        {
            throw new midgardmvc_exception("Variant {$variant} is not defined");
        }
        
        $filters = array();
        foreach ($variants[$variant] as $filter => $options)
        {
            $filters[] = new ezcImageFilter($filter, $options);
        }
        $converter->createTransformation($variant, $filters, array($original->mimetype));

        $original_blob = new midgard_blob($original);
        $transformed_image = tempnam(sys_get_temp_dir(), "{$original->guid}_{$variant}");
        $converter->transform
        (
            $variant,
            $original_blob->get_path(), 
            $transformed_image 
        );

        midgardmvc_core::get_instance()->authorization->enter_sudo('midgardmvc_helper_attachmentserver');

        // Create a child attachment and copy the transformed image there
        $attachment = $original->create_attachment($variant, $original->title, $original->mimetype);
        $attachment_blob = new midgard_blob($attachment);
        $handler = $attachment_blob->get_handler();
        fwrite($handler, file_get_contents($transformed_image));
        fclose($handler);
        $attachment->update();

        midgardmvc_core::get_instance()->authorization->leave_sudo();
        return $attachment;
    }

    /**
     * Gets an attachment for named location in object
     *
     * @param mixed $parent either midgard_object object or guid
     * @param string $location the locationname
     * @return object or boolean false
     */
    public static function get_by_locationname($parent, string $location)
    {
        $qb = midgardmvc_helper_attachmentserver_attachment::new_query_builder();
        $qb->add_constraint('locationname', '=', $location);
        if (is_object($parent))
        {
            $qb->add_constraint('parentguid', '=', $parent->guid);
        }
        else
        {
            $qb->add_constraint('parentguid', '=', $parent);
        }
        $qb->set_limit(1);
        $attachments = $qb->execute();
        unset($qb);
        if (empty($attachments))
        {
            return false;
        }
        return $attachments[0];
    }

    /**
     * Gets an attachment for named location in object
     *
     * @param mixed $parent either midgard_object object or guid
     * @param string $location the locationname
     * @param boolean $generate_if_missing generate the variant if it does not exist
     * @return object or boolean false
     * @see midgardmvc_helper_attachmentserver_helpers::get_by_locationname()
     * @see midgardmvc_helper_attachmentserver_helpers::get_variant()
     */
    public static function get_variant_by_locationname($parent, string $location, string $variant, $generate_if_missing = false)
    {
        $parent_att = midgardmvc_helper_attachmentserver_helpers::get_by_locationname($parent, $location);
        if (!$parent_att)
        {
            return false;
        }
        return midgardmvc_helper_attachmentserver_helpers::get_variant($parent_att, $variant, $generate_if_missing);
    }

    /**
     * Render IMG tag for named location
     *
     * @param mixed $parent either midgard_object object or guid
     * @param string $location the locationname
     * @param string $variant (optional) variant to use
     * @param boolean $generate_if_missing generate the variant if it does not exist
     * @param string $placeholder whether to use placeholde, valid options are 'auto' (placeholder shown for those that can upload new image), true & false (always/never show placeholder)
     * @return string of img tag or boolean false on failure
     * @see midgardmvc_helper_attachmentserver_helpers::render_location_placeholder()
     * @see midgardmvc_helper_attachmentserver_helpers::get_by_locationname()
     * @see midgardmvc_helper_attachmentserver_helpers::get_variant()
     */
    public static function render_location_image($parent, string $location, $variant = null,  $generate_if_missing = true, $placeholder = 'auto')
    {
        $parent_att = midgardmvc_helper_attachmentserver_helpers::get_by_locationname($parent, $location);
        if (!$parent_att)
        {
            switch ($placeholder)
            {
                case 'auto':
                    if (is_object($parent))
                    {
                        $parent_obj = $parent;
                    }
                    else
                    {
                        $parent_obj = midgard_object_class::get_object_by_guid($parent);
                    }
                    if (!midgardmvc_core::get_instance()->authorization->can_do('midgard:create', $parent_obj))
                    {
                        return false;
                    }
                    // Fall through intentional
                case true:
                    return midgardmvc_helper_attachmentserver_helpers::render_location_placeholder($parent, $location, $variant);
                    break;
                case false:
                    return false;
            }
        }
        $extra_info = array('mgd:locationname' => $location);
        if ($variant)
        {
            return midgardmvc_helper_attachmentserver_helpers::render_image_variant($parent_att, $variant, $exta_info);
        }
        return midgardmvc_helper_attachmentserver_helpers::render_image($parent_att, $exta_info);

    }

    /**
     * Renders a placeholder image
     *
     * @param mixed $parent either midgard_object object or guid
     * @param string $location the locationname
     * @param string $variant (optional) variant to use
     * @see midgardmvc_helper_attachmentserver_helpers::render_location_image()
     */
    public static function render_location_placeholder($parent, string $location, $variant = null)
    {
        $url = str_replace('__MIDGARDMVC_STATIC_URL__', MIDGARDMVC_STATIC_URL, midgardmvc_core::get_instance()->configuration->attachmentserver_placeholder_url);
        $size_line = null;
        if (!is_null($variant))
        {
            // Fetch the variant config to get dimensions
            $variants = midgardmvc_core::get_instance()->configuration->attachmentserver_variants;
            if (!isset($variants[$variant]))
            {
                throw new midgardmvc_exception("Variant {$variant} is not defined");
            }
            $size_line = "width='{$variants[$variant]['width']}' height='{$variants[$variant]['height']}'";
            if (isset($variants[$variant]['placeholder_url']))
            {
                $url = str_replace('__MIDGARDMVC_STATIC_URL__', MIDGARDMVC_STATIC_URL, $variants[$variant]['placeholder_url']); 
            }
        }
        if (is_object($parent))
        {
            $parent_guid = $parent->guid;
        }
        else
        {
            $parent_guid = $parent;
        }
        // PONDER: is the typeof redundant (or wrong) ?
        // TODO: Better way to mark this is placeholder to be actioned on
        return "<img src='{$url}' {$size_line} mgd:parentguid='{$parent_guid}' mgd:locationname='{$location}' typeof='http://purl.org/dc/dcmitype/Image' mgd:placeholder='true' />";
    }

    /**
     * Render IMG tag for given attachment using the variant server
     *
     * @param mixed $attachment either midgard_object object or guid 
     * @param string $variant variant to use
     * @param array $extra_info array of extra information propertie to set.
     * @return string of img tag or boolean false on failure
     */
    public static function render_image_variant($attachment, string $variant, $extra_info = array())
    {
        if (is_object($attachment))
        {
            $attachment_obj = $attachment;
        }
        else
        {
            $attachment_obj = new midgard_attachment($attachment);
        }
        // TODO: How to get the full host url (including prefix) ?
        $url = "/mgd:attachment/{$attachment_obj->guid}/{$variant}/{$attachment_obj->name}";

        // Load size from the variant if it's already created, otherwise use the configured size
        $variant_attachment = midgardmvc_helper_attachmentserver_helpers::get_variant($attachment_obj, $variant, false);
        if (!$variant_attachment)
        {
            $size = midgardmvc_helper_attachmentserver_helpers::get_variant_size($variant);
        }
        else
        {
            $size = midgardmvc_helper_attachmentserver_helpers::get_attachment_size($variant_attachment);
        }
        midgardmvc_helper_attachmentserver_helpers::insert_common_info($attachment_obj, $extra_info);
        $extra_str = midgardmvc_helper_attachmentserver_helpers::encode_to_attributes($extra_info);
        
        // PONDER: is the typeof redundant (or wrong) ?
        return "<img src='{$url}' {$size[3]} {$extra_str} typeof='http://purl.org/dc/dcmitype/Image' mgd:parentguid='{$attachment_obj->parentguid}' mgd:guid='{$attachment_obj->guid}' />";
    }

    /**
     * Calls the php getimagesize() for given attachment
     *
     * @param midgard_attachment $attachment 
     * @see getimagesize()
     */
    public static function get_attachment_size(midgard_attachment $attachment_obj)
    {
        $attachment_blob = new midgard_blob($attachment_obj);
        return getimagesize($attachment_blob->get_path());
    }

    /**
     * Returns the configured size of a variant in the style of PHP getimagesize()
     *
     * @param string $variant
     * @see getimagesize()
     */
    public static function get_variant_size(string $variant)
    {
        $size = array();
        $variants = midgardmvc_core::get_instance()->configuration->attachmentserver_variants;
        if (!isset($variants[$variant]))
        {
            throw new midgardmvc_exception("Variant {$variant} is not defined");
        }
        $size[0] = $variants[$variant]['width'];
        $size[1] = $variants[$variant]['height'];
        $size[2] = null; // We don't know/care
        $size[3] = "width='{$variants[$variant]['width']}' height='{$variants[$variant]['height']}'";
        return $size;
    }

    /**
     * Inserts the attachment title etc to the extra_info array
     *
     * @param midgard_attachment $attachment reference to the attachment object 
     * @param array $extra_info reference to the info array
     */
    public static function insert_common_info(&$attachment_obj, &$extra_info)
    {
        $extra_info['alt'] = $attachment_obj->title;
        $extra_info['title'] = $attachment_obj->title;
    }

    /**
     * Takes an array of key/value pairs and returns a string usable in html tag as attributes
     *
     * @param array $extra_info  the info array
     * @return string
     */
    public static function encode_to_attributes($extra_info)
    {
        $extra_str = '';
        foreach ($extra_info as $k => $v)
        {
            $extra_str .= $k .'="' .  str_replace('"', '&quot;', $v) . '" ';
        }
        return $extra_str;
    }

    /**
     * Render IMG tag for given attachment
     *
     * @param mixed $attachment either midgard_object object or guid 
     * @param array $extra_info array of extra information propertie to set.
     * @return string of img tag or boolean false on failure
     */
    public static function render_image($attachment, $extra_info = array())
    {
        if (is_object($attachment))
        {
            $attachment_obj = $attachment;
        }
        else
        {
            $attachment_obj = new midgard_attachment($attachment);
        }
        
        // TODO: How to get the full host url (including prefix) ?
        $url = "/mgd:attachment/{$attachment_obj->guid}/{$attachment_obj->name}";

        $size = midgardmvc_helper_attachmentserver_helpers::get_attachment_size($attachment_obj);
        midgardmvc_helper_attachmentserver_helpers::insert_common_info($attachment_obj, $extra_info);
        $extra_str = midgardmvc_helper_attachmentserver_helpers::encode_to_attributes($extra_info);

        // PONDER: is the typeof redundant (or wrong) ?
        return "<img src='{$url}' {$size[3]} {$extra_str} typeof='http://purl.org/dc/dcmitype/Image' mgd:parentguid='{$attachment_obj->parentguid}' mgd:guid='{$attachment_obj->guid}' />";
    }

}
?>