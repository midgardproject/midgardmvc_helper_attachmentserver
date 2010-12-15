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
        $variant = midgardmvc_helper_attachmentserver_helpers::get_variant($original_attachment, $args['variant']);
        if ($variant)
        {
            if (!midgardmvc_helper_attachmentserver_helpers::variant_is_fresh($variant, $original_attachment))
            {
                $variant = midgardmvc_helper_attachmentserver_helpers::generate_variant($original_attachment, $args['variant'], true);
            }
        }
        else
        {
            $variant = midgardmvc_helper_attachmentserver_helpers::generate_variant($original_attachment, $args['variant']);
        }

        $this->serve_attachment($variant);
    }
}
?>