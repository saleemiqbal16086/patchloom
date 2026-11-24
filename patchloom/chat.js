(() => {
    let busy = false;
    document.addEventListener('submit', async event => {
        const form = event.target;
        const panel = form.closest('#pl-app');
        if (!panel || !event.submitter || !window.fetch) return;
        event.preventDefault();
        if (busy) return;
        const button = event.submitter;
        const data = new FormData(form);
        data.set(button.name, button.value);
        data.set('action', 'patchloom_chat_ui');
        const input = form.querySelector('textarea[name=request]');
        const settingsOpen = !!panel.querySelector('.pl-settings[open]');
        const log = panel.querySelector('.pl-feed');
        const pending = document.createElement('div');
        if (input && ['chat','preview'].includes(button.value)) {
            const bubble = document.createElement('p');
            bubble.className='pl-out';
            bubble.textContent=input.value;
            pending.append(bubble);
        }
        const status=document.createElement('div');
        status.className='pl-progress';
        status.setAttribute('role','status');
        const labels={work_read:'Reading selected templates', work_import:'Importing products',work_plan:'Preparing template changes',work_apply:'Saving approved changes', file_read:'Reading the file',file_write:'Saving the file',apply:'Applying your revision',test:'Checking Arena connection'};
        status.textContent=labels[button.value] || 'Working on your request';
        const dots=document.createElement('span');
        dots.className='pl-dots';
        for(let i=0;i<3;i++) dots.append(document.createElement('i'));
        status.append(dots);
        pending.append(status);
        log.append(pending);
        status.scrollIntoView({block:'nearest'});
        busy=true;
        panel.setAttribute('aria-busy','true');
        try {
            const response=await fetch(window.ajaxurl,{method:'POST',body:data,credentials:'same-origin'});
            if (!response.ok) throw new Error();
            const result=await response.json();
            if (!result.success || !result.data.html) throw new Error();
            const doc=new DOMParser().parseFromString(result.data.html,'text/html');
            const next=doc.querySelector('#pl-app');
            if (!next) throw new Error();
            panel.replaceWith(next);
            if (settingsOpen) next.querySelector('.pl-settings').open=true;
            const alert=next.querySelector('[role=alert]');
            const step=next.querySelector('button[value=services_next]');
            if (!alert && step && ['services_prepare','services_next'].includes(button.value)) {
                window.setTimeout(() => { if (step.isConnected) step.form.requestSubmit(step); }, 100);
            }
            if (alert && input) next.querySelector('#patchloom-message').value=input.value;
            (alert || next.querySelector('.pl-feed > :last-child') || next).scrollIntoView({block:'nearest'});
        } catch {
            pending.replaceChildren();
            const error=document.createElement('p');
            error.setAttribute('role','alert');
            error.textContent='The server response could not be confirmed. Refresh to check the saved state before retrying.';
            pending.append(error);
        } finally {busy=false;panel.removeAttribute('aria-busy');}
    });
    document.addEventListener('keydown',e=>{
        if (e.target.id==='patchloom-message' && (e.ctrlKey||e.metaKey) && e.key==='Enter') {
            e.preventDefault();e.target.form.requestSubmit(e.target.form.querySelector('[value=chat]'));
        }
    });
})();
