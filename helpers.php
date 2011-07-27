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
    public static function get_variant($parent, $variant, $generate_if_missing = false)
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
    public static function get_fresh_variant($parent, $variant_name, $generate_if_missing = false)
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
     * @param object $original midgard_attachment (or subclass thereof) object
     * @param string $variant the variant name
     * @param boolean $force_regenerate renegeare variant instead of throwing error for existing variant
     * @return object (or will throw exception)
     */
    public static function generate_variant(midgard_attachment $original, $variant, $force_regenerate = false)
    {
        $old_variant = midgardmvc_helper_attachmentserver_helpers::get_variant($original, $variant);
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

        $original_blob = midgardmvc_helper_attachmentserver_helpers::get_blob($original);
        if (empty($original->mimetype))
        {
            $original->mimetype = midgardmvc_helper_attachmentserver_helpers::resolve_mime_type($original_blob->get_path());
            midgardmvc_core::get_instance()->authorization->enter_sudo('midgardmvc_helper_attachmentserver');
            $original->update();
            midgardmvc_core::get_instance()->authorization->leave_sudo();
        }

        $filters = array();
        foreach ($variants[$variant] as $filter => $options)
        {
            $filters[] = new ezcImageFilter($filter, $options);
        }
        $converter->createTransformation($variant, $filters, array($original->mimetype));

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
        if (is_null($attachment))
        {
            throw new midgardmvc_exception("\$original->create_attachment('{$variant}', '{$original->title}', '{$original->mimetype}') failed");
        }
        
        midgardmvc_helper_attachmentserver_helpers::copy_file_to_attachment($transformed_image, $attachment);
        unlink($transformed_image);

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
    public static function get_by_locationname($parent, $location)
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
    public static function get_variant_by_locationname($parent, $location, $variant, $generate_if_missing = false)
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
    public static function render_location_image($parent, $location, $variant = null,  $generate_if_missing = true, $placeholder = 'auto')
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
                        // TODO: switch back to false when we have the TAL macros to use these methods correctly (to check the error status etc)
                        return null;
                    }
                    // Fall through intentional
                case true:
                    return midgardmvc_helper_attachmentserver_helpers::render_location_placeholder($parent, $location, $variant);
                    break;
                case false:
                    // TODO: switch back to false when we have the TAL macros to use these methods correctly (to check the error status etc)
                    return null;
            }
        }
        $extra_info = array('mgd:locationname' => $location);
        if ($variant)
        {
            return midgardmvc_helper_attachmentserver_helpers::render_image_variant($parent_att, $variant, $extra_info);
        }
        return midgardmvc_helper_attachmentserver_helpers::render_image($parent_att, $extra_info);

    }

    /**
     * Renders a placeholder image
     *
     * @param mixed $parent either midgard_object object or guid
     * @param string $location the locationname
     * @param string $variant (optional) variant to use
     * @see midgardmvc_helper_attachmentserver_helpers::render_location_image()
     */
    public static function render_location_placeholder($parent, $location, $variant = null)
    {
        $url = str_replace('__MIDGARDMVC_STATIC_URL__', MIDGARDMVC_STATIC_URL, midgardmvc_core::get_instance()->configuration->attachmentserver_placeholder_url);
        $size_line = null;
        $extra_str = null;
        if (!is_null($variant))
        {
            $size = midgardmvc_helper_attachmentserver_helpers::get_variant_size($variant);
            $size_line = $size[3];
            $extra_str = midgardmvc_helper_attachmentserver_helpers::encode_to_attributes(array('mgd:variant' => $variant));
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
        return "<img src='{$url}' {$size_line} {$extra_str} mgd:parentguid='{$parent_guid}' mgd:locationname='{$location}' typeof='http://purl.org/dc/dcmitype/Image' mgd:placeholder='true' />";
    }

    /**
     * Copies file pointer contents in a stream
     *
     * @param pointer $src source pointer
     * @param pointer $dst destination pointer
     * @param boolean $close close the pointers when done
     */
    public static function file_pointer_copy($src, $dst, $close=true)
    {
        while (! feof($src))
        {
            $buffer = fread($src, 131072); /* 128 kB */
            fwrite($dst, $buffer, 131072);
        }
        if ($close)
        {
            fclose($src);
            fclose($dst);
        }
        return true;
    }

    /**
     * Copy given file contents to given attachment
     *
     * @param string $file file path
     * @param midgard_attachment $attachment attachment
     * @param boolean $close close the pointers when done
     */
    public static function copy_file_to_attachment($file, &$attachment)
    {
        // PONDER user PHPs copy() instead (but what if the BLOB is not in the filesystem?)
        $blob = midgardmvc_helper_attachmentserver_helpers::get_blob($attachment);
        $src = fopen($file, 'rb');
        $dst = $blob->get_handler('wb');
        midgardmvc_helper_attachmentserver_helpers::file_pointer_copy($src, $dst, true);
        $attachment->update();
    }

    /**
     * Copy given attachment contents to given file
     *
     * @param string $file file path
     * @param midgard_attachment $attachment attachment
     * @param boolean $close close the pointers when done
     */
    public static function copy_attachment_to_file(&$attachment, $file)
    {
        // PONDER user PHPs copy() instead (but what if the BLOB is not in the filesystem?)
        $blob = midgardmvc_helper_attachmentserver_helpers::get_blob($attachment);
        $src = $blob->get_handler('rb');
        $dst = fopen($file, 'wb');
        return midgardmvc_helper_attachmentserver_helpers::file_pointer_copy($src, $dst, true);
    }

    /**
     * Wrapper to various approaches for resolving the file mimetype
     *
     * @param string $file_path path to file
     * @return string mimetype
     */
    public static function resolve_mime_type($file_path)
    {
        if (function_exists('mime_content_type'))
        {
            $mtype = mime_content_type($file_path);
            if (!empty($mtype))
            {
                return $mtype;
            }
        }
        if (function_exists('finfo_file'))
        {
            $finfo = finfo_open(FILEINFO_MIME);
            $mtype = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            unset($finfo);
            if (!empty($mtype))
            {
                return $mtype;
            }
        }

        // Finally fall back to getimagesize (that will not work for non-images)
        $info = @getimagesize($file_path);
        if (   is_array($info)
            && isset($info[2])
            && !empty($info[2]))
        {
            return $info[2];
        }

        // Fallback
        return 'application/force-download';
    }

    /**
     * Render IMG tag for given attachment using the variant server
     *
     * @param mixed $attachment either midgard_object object or guid 
     * @param string $variant variant to use
     * @param array $extra_info array of extra information propertie to set.
     * @return string of img tag or boolean false on failure
     */
    public static function render_image_variant($attachment, $variant, $extra_info = array())
    {
        $attachment_obj = midgardmvc_helper_attachmentserver_helpers::get_as_object($attachment);
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
        $extra_info['mgd:variant'] = $variant;
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
        $attachment_blob = midgardmvc_helper_attachmentserver_helpers::get_blob($attachment_obj);
        return getimagesize($attachment_blob->get_path());
    }

    /**
     * Returns the configured size of a variant in the style of PHP getimagesize()
     *
     * @param string $variant
     * @see getimagesize()
     */
    public static function get_variant_size($variant)
    {
        $size = array();
        $variants = midgardmvc_core::get_instance()->configuration->attachmentserver_variants;
        if (!isset($variants[$variant]))
        {
            throw new midgardmvc_exception("Variant {$variant} is not defined");
        }
        if (   (!isset($variants[$variant]['scale'])
            || !is_array($variants[$variant]['scale'])
            || !isset($variants[$variant]['scale']['width'])
            || !isset($variants[$variant]['scale']['height']))
            && (!isset($variants[$variant]['scaleExact'])
            || !is_array($variants[$variant]['scaleExact'])
            || !isset($variants[$variant]['scaleExact']['width'])
            || !isset($variants[$variant]['scaleExact']['height'])))
        {
            throw new midgardmvc_exception("Variant {$variant} does not define scale or scaleExact");
        }

        if (isset($variants[$variant]['scale']))
        {
            $size[0] = $variants[$variant]['scale']['width'];
            $size[1] = $variants[$variant]['scale']['height'];
            $size[2] = null; // We don't know/care
            $size[3] = "width='{$variants[$variant]['scale']['width']}' height='{$variants[$variant]['scale']['height']}'";
        }

        if (isset($variants[$variant]['scaleExact']))
        {
            $size[0] = $variants[$variant]['scaleExact']['width'];
            $size[1] = $variants[$variant]['scaleExact']['height'];
            $size[2] = null; // We don't know/care
            $size[3] = "width='{$variants[$variant]['scaleExact']['width']}' height='{$variants[$variant]['scaleExact']['height']}'";
        }

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
     * Quick helper to return the object we need
     *
     * @param mixed $attachment either midgard_object object or guid 
     * @return midgard_attachment
     */
    public static function get_as_object($attachment)
    {
        if (   is_object($attachment)
            && (   is_a($attachment, 'midgard_attachment')
                // For some reason the MgdSchema extends are not placed to class hierachy
                || is_a($attachment, 'midgardmvc_helper_attachmentserver_attachment'))
            )
        {
            return $attachment;
        }
        if (!mgd_is_guid($attachment))
        {
            throw new midgardmvc_exception("Given argument '{$attachment}' is neither object or GUID");
        }
        return new midgard_attachment($attachment);
    }

    /**
     * Quick helper to return the object we need
     *
     * @param mixed $attachment either midgard_object object or guid 
     * @return midgard_attachment
     */
    public static function get_blob($attachment)
    {
        if (is_a($attachment, 'midgardmvc_helper_attachmentserver_attachment'))
        {
            $use_attachment = new midgard_attachment($attachment->guid);
            return new midgard_blob($use_attachment);
        }
        return new midgard_blob($attachment);
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
        $attachment_obj = midgardmvc_helper_attachmentserver_helpers::get_as_object($attachment);
        
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