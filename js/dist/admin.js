(()=>{var e={n:t=>{var o=t&&t.__esModule?()=>t.default:()=>t;return e.d(o,{a:o}),o},d:(t,o)=>{for(var n in o)e.o(o,n)&&!e.o(t,n)&&Object.defineProperty(t,n,{enumerable:!0,get:o[n]})},o:(e,t)=>Object.prototype.hasOwnProperty.call(e,t)};(()=>{"use strict";const t=flarum.core.compat["admin/app"];var o=e.n(t);function n(e,t){return n=Object.setPrototypeOf?Object.setPrototypeOf.bind():function(e,t){return e.__proto__=t,e},n(e,t)}const a=flarum.core.compat["admin/components/ExtensionPage"];var l=function(e){function t(){return e.apply(this,arguments)||this}var a,l;return l=e,(a=t).prototype=Object.create(l.prototype),a.prototype.constructor=a,n(a,l),t.prototype.content=function(){var e="fof-open-collective.use_legacy_api_key",t=!!Number(this.setting(e)());return[m("div",{className:"container"},m("div",{className:"OpenCollectiveSettings"},m("p",null,o().translator.trans("fof-open-collective.admin.settings.desc",{a:m("a",{href:"https://opencollective.com/dashboard",target:"_blank"})})),this.buildSettingComponent({type:"bool",setting:e,label:o().translator.trans("fof-open-collective.admin.settings.use_legacy_api_key_label"),help:o().translator.trans("fof-open-collective.admin.settings.use_legacy_api_key_help")}),this.buildSettingComponent({type:"text",setting:"fof-open-collective.api_key",label:o().translator.trans("fof-open-collective.admin.settings."+(t?"api_key":"personal_token")+"_label")}),this.buildSettingComponent({type:"text",setting:"fof-open-collective.slug",label:o().translator.trans("fof-open-collective.admin.settings.slug_label")}),m("div",{className:"Form-group"},this.buildSettingComponent({type:"select",setting:"fof-open-collective.group_id",options:o().store.all("groups").reduce((function(e,t){return e[t.id()]=t.nameSingular(),e}),{}),label:o().translator.trans("fof-open-collective.admin.settings.group_label")})),this.submitButton()))]},t}(e.n(a)());o().initializers.add("fof/open-collective",(function(){o().extensionData.for("fof-open-collective").registerPage(l)}))})(),module.exports={}})();
//# sourceMappingURL=admin.js.map