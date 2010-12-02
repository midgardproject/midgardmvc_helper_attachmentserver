<?php
/**
 * @package midgardmvc_helper_attachmentserver
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Common methods for attachment serving.
 *
 * @package midgardmvc_helper_attachmentserver
 */
abstract class midgardmvc_helper_attachmentserver_controllers_base
{
    public function __construct(midgardmvc_core_request $request)
    {
      $this->request = $request;
    }

    public function serve_attachment(midgard_attachment $att)
    {
        if (midgardmvc_core::get_instance()->configuration->enable_attachment_cache)
        {
            // Relocate to attachment serving URL
            midgardmvc_core::get_instance()->head->relocate(midgardmvc_core_helpers_attachment::get_url($att));
        }

        $blob = new midgard_blob($att);
        
        midgardmvc_core::get_instance()->dispatcher->header('Content-type: '.$att->mimetype);
midgardmvc_core::get_instance()->dispatcher->header("Content-Length: " . $att->metadata->size);

        /**
          * If X-Sendfile support is enabled just send correct headers
          */
        if (midgardmvc_core::get_instance()->configuration->enable_xsendfile)
        {
            midgardmvc_core::get_instance()->dispatcher->header('X-Sendfile: ' . $blob->get_path());
        }
        else
        {
            echo $blob->read_content();
        }
        midgardmvc_core::get_instance()->dispatcher->end_request();
    }
}
