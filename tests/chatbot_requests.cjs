const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const button={disabled:false};
const input={value:'Today schedule',dataset:{},reportValidity:()=>true,parentElement:{querySelector:()=>button}};
const box={setAttribute(){},scrollHeight:0};
const messages=[];
const nodes=new Map([['chatInput',input],['chatBox',box]]);
const ctx=vm.createContext({
    AbortController,TypeError,Error,setTimeout,clearTimeout,
    window:{VETRIX_BASE:'/custom/clinic/',VETRIX_CSRF:'test-token'},
    document:{getElementById:id=>nodes.get(id),querySelector:()=>null},
});
vm.runInContext(source.slice(0,source.indexOf('function switchMobileView')),ctx);
ctx.escapeHtml=String;ctx.formatVetrixAnswer=String;
ctx.appendChatMessage=(container,type,html,id)=>{
    const node={textContent:html,classList:{remove(){}},remove(){nodes.delete(id);}};
    if(id)nodes.set(id,node);
    messages.push({type,node});return node;
};
async function run(){
    let finish,calls=0;
    ctx.fetch=(url,options)=>{
        calls++;assert.equal(url,'/custom/clinic/api/chatbot.php');
        assert.equal(options.headers['X-CSRF-Token'],'test-token');
        assert.equal(JSON.parse(options.body).message,'Today schedule');
        return new Promise(resolve=>finish=resolve);
    };
    const pending=ctx.sendChat();
    assert.equal(button.disabled,true);
    input.value='A second draft';await ctx.sendChat();assert.equal(calls,1);
    finish({ok:true,json:async()=>({answer:'Today appointments'})});await pending;
    assert.equal(button.disabled,false);assert.equal(input.value,'A second draft');

    input.value='Failed question';
    ctx.fetch=async()=>({ok:false,json:async()=>({answer:'Your session has expired. Sign in again.'})});
    await ctx.sendChat();
    assert.equal(input.value,'Failed question');assert.equal(button.disabled,false);
    assert.equal(messages.at(-1).node.textContent,'Your session has expired. Sign in again.');

    ctx.fetch=async()=>({ok:false,json:async()=>{throw new SyntaxError('HTML response');}});
    await assert.rejects(ctx.requestVetrixAnswer('hello'),/temporarily unavailable/);
    ctx.fetch=async()=>({ok:true,json:async()=>({answer:''})});
    await assert.rejects(ctx.requestVetrixAnswer('hello'),/empty reply/);
    ctx.fetch=async()=>{throw new TypeError('Failed to fetch');};
    await assert.rejects(ctx.requestVetrixAnswer('hello'),/Could not connect/);
    ctx.fetch=async()=>{const error=new Error();error.name='AbortError';throw error;};
    await assert.rejects(ctx.requestVetrixAnswer('hello'),/took too long/);

    input.value='Original question';
    ctx.fetch=()=>new Promise((resolve,reject)=>finish=reject);
    const failed=ctx.sendChat();input.value='Keep this newer draft';finish(new TypeError());await failed;
    assert.equal(input.value,'Keep this newer draft');assert.equal(button.disabled,false);
    console.log('Chat request checks passed: custom base URL, CSRF, duplicate-send guard, draft preservation, session errors, invalid responses, network failure, timeout.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
