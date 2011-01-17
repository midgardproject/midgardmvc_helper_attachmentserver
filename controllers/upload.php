<?php
/**
 * @package midgardmvc_helper_attachmentserver
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Simple receiver for uploaded files
 *
 * @package midgardmvc_helper_attachmentserver
 */
class midgardmvc_helper_attachmentserver_controllers_upload extends midgardmvc_helper_attachmentserver_controllers_base
{
    public $parent = null;

    public function load_parent()
    {
        if (   !isset($_POST['parentguid'])
            || empty($_POST['parentguid']))
        {
            throw new midgardmvc_exception("Parent object GUID not defined");
        }
        
        try
        {
            $this->parent = midgard_object_class::get_object_by_guid($_POST['parentguid']);
        }
        catch (midgard_error_exception $e)
        {
            throw new midgardmvc_exception_notfound("Object {$_POST['parentguid']}: " . $e->getMessage());
        }
    }

    /**
     * Allows upload of new files or update of old ones
     *
     * If succesfull will redirect to the attachment url
     */
    public function post_upload(array $args)
    {
        $this->load_parent();

        if (!isset($_FILES['file']))
        {
            throw new midgardmvc_exception("No file received");
        }
        $file =& $_FILES['file'];
        
        if (   isset($file['error'])
            && $file['error'] !== UPLOAD_ERR_OK)
        {
            throw new midgardmvc_exception("Upload got error code {$file['error']}");
        }
        
        if (   !isset($file['name'])
            || empty($file['name']))
        {
            throw new midgardmvc_exception("Could not get file name");
        }
        
        $title = $file['name'];
        if (   isset($_POST['title'])
            && !empty($_POST['title']))
        {
            $title = $_POST['title'];
        }
        if (   !isset($file['type'])
            || empty($file['type']))
        {
            $file['type'] = midgardmvc_helper_attachmentserver_helpers::resolve_mime_type($file['tmp_name']);
        }
        
        if (   isset($_POST['locationname'])
            && !empty($_POST['locationname']))
        {
            $attachment = midgardmvc_helper_attachmentserver_helpers::get_by_locationname($this->parent, $_POST['locationname']);
            if (!$attachment)
            {
                // Workaround
                $attachment = $this->parent->create_attachment($file['name'], $title, $file['type']);
                if (is_null($attachment))
                {
                    throw new midgardmvc_exception("\$parent->create_attachment('{$file['name']}', '{$title}', '{$file['type']}') failed");
                }
                $attachment_ext = new midgardmvc_helper_attachmentserver_attachment($attachment->guid);
                $attachment_ext->locationname = $_POST['locationname'];
                $attachment_ext->update();
            }
        }
        elseif (   isset($_POST['guid'])
                && !empty($_POST['guid']))
        {
            $attachment = new midgard_attachment($_POST['guid']);
        }
        else
        {
            $attachment = $this->parent->create_attachment($file['name'], $title, $file['type']);
            if (is_null($attachment))
            {
                throw new midgardmvc_exception("\$parent->create_attachment('{$file['name']}', '{$title}', '{$file['type']}') failed");
            }
        }

        midgardmvc_helper_attachmentserver_helpers::copy_file_to_attachment($file['tmp_name'], $attachment);
        $this->handle_result($attachment);
    }

    public function handle_result($attachment)
    {
        if (   isset($_POST['variant'])
            && !empty($_POST['variant']))
        {
            // TODO: How to get the full host url (including prefix) ?
            midgardmvc_core::get_instance()->head->relocate("/mgd:attachment/{$attachment->guid}/{$_POST['variant']}/{$attachment->name}");
        }
        // TODO: How to get the full host url (including prefix) ?
        midgardmvc_core::get_instance()->head->relocate("/mgd:attachment/{$attachment->guid}/{$attachment->name}");
    }
}
?>
