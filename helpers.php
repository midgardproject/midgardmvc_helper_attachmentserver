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
     * @return object or boolean false
     */
    public static function get_variant($parent, string $variant)
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
            return false;
        }
        return $variants[0];
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
}
?>