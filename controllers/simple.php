<?php
/**
 * @package midgardmvc_helper_attachmentserver
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Very simple attachment serving by guid.
 *
 * @package midgardmvc_helper_attachmentserver
 */
class midgardmvc_helper_attachmentserver_controllers_simple extends midgardmvc_helper_attachmentserver_controllers_base
{
    /**
     * Function serves the attachment by provided guid and exits.
     * @todo: Permission handling
     * @todo: Direct filesystem serving
     */
    public function get_file(array $args)
    {
        try
        {
            $att = new midgard_attachment($args['guid']);
        }
        catch (midgard_error_exception $e)
        {
            throw new midgardmvc_exception_notfound("Attachment not found: " . $e->getMessage());
        }

        $this->serve_attachment($att);
    }
}
?>
