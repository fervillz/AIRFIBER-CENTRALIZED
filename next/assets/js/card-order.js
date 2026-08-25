(function(){
'use strict';
if(!document.body||!document.body.classList.contains('afcn-page'))return;
const CARD='.afcn-card,.afcn-module-card',PRESS=600,MOVE=7,cfg=window.afcnApp||{},changed=new Set();
let mode=false,hold=null,drag=null,blockClickUntil=0,wireTimer=0;
function storeKey(){return'afcn.card-order.v1.user.'+String(cfg.userId||0)}
function read(){try{return JSON.parse(localStorage.getItem(storeKey())||'{}')||{}}catch(e){return{}}}
function write(v){try{localStorage.setItem(storeKey(),JSON.stringify(v));return true}catch(e){return false}}
function text(v){return String(v||'').replace(/\s+/g,' ').trim().toLowerCase()}
function hash(v){let h=2166136261,s=String(v||'');for(let i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619)}return(h>>>0).toString(36)}
function scope(){const n=document.querySelector('.afcn-nav [data-afcn-module].is-active');return n&&n.dataset.afcnModule?n.dataset.afcnModule:(location.hash.replace(/^#/,'')||'dashboard')}
function cards(parent){return parent?Array.from(parent.children).filter(n=>n.matches&&n.matches(CARD)):[]}
function visible(n){return!!(n&&!n.hidden&&n.getClientRects().length)}
function validParent(card){if(!card||!card.parentElement)return null;return cards(card.parentElement).filter(visible).length>1?card.parentElement:null}
function cardKey(card,index){
 if(card.dataset.afcnCardKey)return'd:'+card.dataset.afcnCardKey;
 if(card.id)return'i:'+card.id;
 const m=card.querySelector('input[name="module_id"]');if(m&&m.value)return'm:'+m.value;
 const c=card.querySelector('input[name="connection_id"]');if(c&&c.value)return'c:'+c.value;
 const u=card.querySelector('[data-afcn-user-edit]');if(u&&u.dataset.afcnUserEdit)return'u:'+u.dataset.afcnUserEdit;
 if(card.dataset.afcnSearch)return's:'+hash(text(card.dataset.afcnSearch));
 const l=card.querySelector('.afcn-stat-label,.afcn-module-card-title-text,.afcn-user-card-subtitle,.afcn-card-header h2,.afcn-card-header h3,h3,h2');
 return l&&text(l.textContent)?'l:'+hash(text(l.textContent)):'f:'+hash(text(card.textContent).slice(0,180)||String(index));
}
function nodeToken(node){
 if(node.id)return'#'+node.id;
 const cls=Array.from(node.classList||[]).filter(x=>x.indexOf('afcn-')===0&&!x.startsWith('is-')).sort().slice(0,3),base=node.tagName.toLowerCase()+(cls.length?'.'+cls.join('.'):'');
 if(!node.parentElement)return base;
 const same=Array.from(node.parentElement.children).filter(s=>{if(s.tagName!==node.tagName)return false;const sc=Array.from(s.classList||[]).filter(x=>x.indexOf('afcn-')===0&&!x.startsWith('is-')).sort().slice(0,3);return sc.join('.')===cls.join('.')});
 return base+':'+Math.max(0,same.indexOf(node));
}
function parentToken(parent){
 if(parent.dataset.afcnCardOrderKey)return parent.dataset.afcnCardOrderKey;
 if(parent.dataset.afcnCardGroup)return parent.dataset.afcnCardOrderKey=scope()+'|g:'+parent.dataset.afcnCardGroup;
 const bits=[];let n=parent,stop=document.getElementById('afcn-module-stage')||document.body;
 while(n&&n!==stop&&n!==document.body&&bits.length<4){bits.unshift(nodeToken(n));n=n.parentElement}
 return parent.dataset.afcnCardOrderKey=scope()+'|'+bits.join('>');
}
function containers(root){const out=[];Array.from((root||document).querySelectorAll(CARD)).forEach(card=>{const p=card.parentElement;if(p&&cards(p).length>1&&!out.includes(p))out.push(p)});return out}
function restore(parent,order){if(!Array.isArray(order)||!order.length)return;const current=cards(parent),map=new Map();current.forEach((c,i)=>map.set(cardKey(c,i),c));const next=[];order.forEach(k=>{if(map.has(k)){next.push(map.get(k));map.delete(k)}});current.forEach((c,i)=>{if(map.has(cardKey(c,i)))next.push(c)});if(current.length!==next.length||current.some((c,i)=>c!==next[i])){const anchor=current[current.length-1].nextSibling;next.forEach(c=>parent.insertBefore(c,anchor))}}
function wire(root){const data=read();containers(root||document).forEach(p=>restore(p,data[parentToken(p)]));if(mode)markCards()}
function save(){if(!changed.size)return;const data=read();changed.forEach(p=>{if(p&&p.isConnected)data[parentToken(p)]=cards(p).map((c,i)=>cardKey(c,i))});write(data)}
function indicator(){let n=document.querySelector('[data-afcn-card-order-indicator]');if(!n){n=document.createElement('div');n.className='afcn-card-order-indicator';n.dataset.afcnCardOrderIndicator='1';n.textContent='Arrange cards · drag within each group · long press a card to save and exit';n.hidden=true;document.body.appendChild(n)}return n}
function markCards(){document.querySelectorAll('[data-afcn-card-reorder]').forEach(n=>n.removeAttribute('data-afcn-card-reorder'));containers(document).forEach(p=>{if(cards(p).filter(visible).length>1)cards(p).forEach(c=>c.dataset.afcnCardReorder='1')})}
function enter(){if(mode)return;mode=true;document.body.classList.add('afcn-card-reorder-mode');markCards();indicator().hidden=false;if(navigator.vibrate)navigator.vibrate(18)}
function finishDrag(){if(!drag)return;drag.card.classList.remove('is-afcn-card-dragging');drag=null}
function exit(saveIt){if(!mode)return;finishDrag();if(saveIt!==false)save();changed.clear();mode=false;document.body.classList.remove('afcn-card-reorder-mode');document.querySelectorAll('[data-afcn-card-reorder]').forEach(n=>n.removeAttribute('data-afcn-card-reorder'));indicator().hidden=true;if(saveIt!==false&&window.AirfiberNext&&window.AirfiberNext.toast)window.AirfiberNext.toast('Card arrangement saved.',false)}
function closestCard(target){const c=target&&target.closest?target.closest(CARD):null;return c||null}
function clearHold(){if(!hold)return;clearTimeout(hold.timer);hold.card.classList.remove('is-afcn-card-pressing');hold=null}
function distance(e){if(!hold)return 0;return Math.hypot(e.clientX-hold.x,e.clientY-hold.y)}
function nearest(parent,x,y){let best=null,dist=Infinity;cards(parent).filter(c=>c!==drag.card&&visible(c)).forEach(c=>{const r=c.getBoundingClientRect(),dx=x<r.left?r.left-x:(x>r.right?x-r.right:0),dy=y<r.top?r.top-y:(y>r.bottom?y-r.bottom:0),d=dx*dx+dy*dy;if(d<dist){dist=d;best=c}});return best}
function isMultiColumn(parent){const items=cards(parent).filter(c=>c!==drag.card&&visible(c));if(items.length<2)return false;const a=items[0].getBoundingClientRect(),b=items[1].getBoundingClientRect();return Math.abs(a.top-b.top)<Math.min(a.height,b.height)*.45}
function reorder(parent,target,x,y){if(!target||target.parentElement!==parent||target===drag.card)return;const r=target.getBoundingClientRect(),before=isMultiColumn(parent)&&y>=r.top&&y<=r.bottom?x<r.left+r.width/2:y<r.top+r.height/2,ref=before?target:target.nextSibling;if(ref===drag.card||(!ref&&drag.card===parent.lastElementChild))return;parent.insertBefore(drag.card,ref);changed.add(parent)}
document.addEventListener('pointerdown',e=>{
 if(e.pointerType==='mouse'&&e.button!==0)return;const card=closestCard(e.target);if(!card||!validParent(card)||e.target.closest('input,textarea,select,[contenteditable="true"]'))return;
 clearHold();card.classList.add('is-afcn-card-pressing');const wasMode=mode;hold={card,x:e.clientX,y:e.clientY,pointerId:e.pointerId,wasMode,dragged:false,timer:setTimeout(()=>{if(!hold||hold.card!==card||hold.dragged)return;blockClickUntil=Date.now()+700;wasMode?exit(true):enter()},PRESS)};if(mode)e.preventDefault();
},true);
document.addEventListener('pointermove',e=>{
 if(drag){e.preventDefault();const t=nearest(drag.parent,e.clientX,e.clientY);reorder(drag.parent,t,e.clientX,e.clientY);return}
 if(!hold||e.pointerId!==hold.pointerId)return;const d=distance(e);if(!hold.wasMode){if(d>MOVE)clearHold();return}if(d>MOVE){clearTimeout(hold.timer);hold.dragged=true;drag={card:hold.card,parent:hold.card.parentElement,pointerId:e.pointerId};drag.card.classList.add('is-afcn-card-dragging');e.preventDefault()}
},{capture:true,passive:false});
function endPointer(e){if(drag&&e.pointerId===drag.pointerId)finishDrag();if(hold&&e.pointerId===hold.pointerId)clearHold()}
document.addEventListener('pointerup',endPointer,true);document.addEventListener('pointercancel',endPointer,true);
document.addEventListener('click',e=>{const card=closestCard(e.target);if(card&&(mode||Date.now()<blockClickUntil)){e.preventDefault();e.stopImmediatePropagation();return}if(mode&&e.target.closest('.afcn-nav [data-afcn-module]'))exit(true)},true);
document.addEventListener('contextmenu',e=>{const card=closestCard(e.target);if(card&&(mode||(hold&&hold.card===card)))e.preventDefault()},true);
document.addEventListener('keydown',e=>{if(mode&&e.key==='Escape'){e.preventDefault();exit(true)}});
window.addEventListener('beforeunload',()=>{if(mode)save()});
function schedule(root){clearTimeout(wireTimer);wireTimer=setTimeout(()=>wire(root||document),50)}
function observe(root){if(!root||!window.MutationObserver)return;new MutationObserver(records=>{if(records.some(r=>Array.from(r.addedNodes).concat(Array.from(r.removedNodes)).some(n=>n.nodeType===1&&(n.matches(CARD)||(n.querySelector&&n.querySelector(CARD))))))schedule(root)}).observe(root,{childList:true,subtree:true})}
document.addEventListener('afcn:module:loaded',()=>{if(mode)exit(true);schedule(document.getElementById('afcn-module-stage')||document)});document.addEventListener('afcn:chunk:loaded',e=>schedule(e.detail&&e.detail.target?e.detail.target:document));
observe(document.getElementById('afcn-module-stage'));observe(document.querySelector('.afcn-utility-drawer-body'));wire(document);
window.AirfiberCardOrder=Object.freeze({wire,enter,exit:()=>exit(true),active:()=>mode});
}());
