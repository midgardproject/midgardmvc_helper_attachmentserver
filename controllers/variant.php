<?php
/**
 * @package midgardmvc_helper_attachmentserver
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Image variant serving
 *
 * @package midgardmvc_helper_attachmentserver
 */
class midgardmvc_helper_attachmentserver_controllers_variant extends midgardmvc_helper_attachmentserver_controllers_base
{
    private $variants = array();

    public function __construct(midgardmvc_core_request $request)
    {
        $this->variants = midgardmvc_core::get_instance()->configuration->attachmentserver_variants;
        parent::__construct($request);
    }

    /**
     * Function serves the attachment variant.
     */
    public function get_variant(array $args)
    {
        if (!isset($this->variants[$args['variant']]))
        {
            throw new midgardmvc_exception_notfound("The requested variant is not available");
        }

        $original_attachment = new midgard_attachment($args['guid']);

        // Check whether the variant already exists
        $qb = midgard_attachment::new_query_builder();
        $qb->add_constraint('name', '=', $args['variant']);
        $qb->add_constraint('parentguid', '=', $original_attachment->guid);
        $variants = $qb->execute();
        if (count($variants) > 0)
        {
            // Variant found in cache, check timestamp and serve as needed
            $variant = $variants[0];
            if ($variant->metadata->revised > $original_attachment->metadata->revised)
            {
                $this->serve_attachment($variants[0]);
            }

            // Stale variant, delete and go to regeneration
            midgardmvc_core::get_instance()->authorization->enter_sudo('midgardmvc_helper_attachmentserver');
            $variants[0]->delete();
            midgardmvc_core::get_instance()->authorization->leave_sudo();
        }

        $variant = $this->generate_variant($original_attachment, $args['variant']);
        $this->serve_attachment($variant);
    }

    private function generate_variant(midgard_attachment $original, $variant)
    {
        midgardmvc_core::get_instance()->component->load_library('ImageConverter');

        $settings = new ezcImageConverterSettings
        (
            array
            (
                new ezcImageHandlerSettings( 'ImageMagick', 'ezcImageImagemagickHandler' ),
            )
        );
        $converter = new ezcImageConverter($settings);

        $filters = array();
        foreach ($this->variants[$variant] as $filter => $options)
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
