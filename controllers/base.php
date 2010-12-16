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

        $blob = midgardmvc_helper_attachmentserver_helpers::get_blob($att);
        $stream = $blob->get_handler('rb');
        $mtime = $att->metadata->revised->format('r');

        // Generate ETag
        $hash = hash_init('sha1');
        hash_update_stream($hash, $stream);
        hash_update($hash, $mtime);
        $etag = hash_final($hash);
        fclose($stream);

        if (   isset($_SERVER['HTTP_IF_NONE_MATCH'])
            && $etag == $_SERVER['HTTP_IF_NONE_MATCH'])
        {
            throw new midgardmvc_exception_httperror("File has not changed", 304);
        }

        if (   isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            && $mtime == $_SERVER['HTTP_IF_MODIFIED_SINCE'])
        {
            throw new midgardmvc_exception_httperror("Not modified since {$mtime}", 304);
        }

        // Safety for broken mimetypes        
        if (empty($att->mimetype))
        {
            $att->mimetype = midgardmvc_helper_attachmentserver_helpers::resolve_mime_type($blob->get_path());
            midgardmvc_core::get_instance()->authorization->enter_sudo('midgardmvc_helper_attachmentserver');
            $att->update();
            midgardmvc_core::get_instance()->authorization->leave_sudo();
        }

        midgardmvc_core::get_instance()->dispatcher->header('Content-type: '.$att->mimetype);
        midgardmvc_core::get_instance()->dispatcher->header('ETag: '.$etag);
        midgardmvc_core::get_instance()->dispatcher->header('Last-Modified: '.$mtime);

        /**
          * If X-Sendfile support is enabled just send correct headers
          */
        if (midgardmvc_core::get_instance()->configuration->enable_xsendfile)
        {
            midgardmvc_core::get_instance()->dispatcher->header('X-Sendfile: ' . $blob->get_path());
        }
        else
        {
            // TODO: Investigate ways to pass the file info in smaller chunks and outside of the output buffer (think 100MB files...)
            echo $blob->read_content();
            /*
            //$stream = $blob->get_handler('rb');
            $stream->rewind();
            fpassthru($stream);
            */
        }
        midgardmvc_core::get_instance()->dispatcher->end_request();
    }
}
