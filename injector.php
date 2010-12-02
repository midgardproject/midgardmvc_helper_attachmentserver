<?php
class midgardmvc_helper_attachmentserver_injector
{
    public function inject_process(midgardmvc_core_request $request)
    {
        // We inject the process to provide our additional root routes
        $request->add_component_to_chain(midgardmvc_core::get_instance()->component->get('midgardmvc_helper_attachmentserver'), true);
    }
}
?>
