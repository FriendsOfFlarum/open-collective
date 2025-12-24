import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Link from 'flarum/common/components/Link';
import Group from 'flarum/common/models/Group';
import type Mithril from 'mithril';

export default class ExtensionSettingsPage extends ExtensionPage {
  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
  }

  content() {
    const useLegacySettingKey = 'fof-open-collective.use_legacy_api_key';
    const useLegacySetting = !!Number(this.setting(useLegacySettingKey)());

    return (
      <div className="container">
        <div className="OpenCollectiveSettings">
          <div className="Form">
            {this.buildSettingComponent({
              type: 'bool',
              setting: useLegacySettingKey,
              label: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_label'),
              help: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_help'),
            })}

            {this.buildSettingComponent({
              type: 'password',
              setting: 'fof-open-collective.api_key',
              label: app.translator.trans(`fof-open-collective.admin.settings.${useLegacySetting ? 'api_key' : 'personal_token'}_label`),
              help: app.translator.trans(`fof-open-collective.admin.settings.${useLegacySetting ? 'api_key' : 'personal_token'}_help`, {
                a: <Link href="https://opencollective.com/applications" target="_blank" />,
              }),
            })}

            {this.buildSettingComponent({
              type: 'string',
              setting: 'fof-open-collective.slug',
              label: app.translator.trans('fof-open-collective.admin.settings.slug_label'),
              help: app.translator.trans('fof-open-collective.admin.settings.slug_help'),
              required: true,
            })}

            {this.buildSettingComponent({
              type: 'select',
              setting: 'fof-open-collective.group_id',
              label: app.translator.trans('fof-open-collective.admin.settings.group_label'),
              help: app.translator.trans('fof-open-collective.admin.settings.group_help'),
              options: app.store.all<Group>('groups').reduce<Record<string, any>>((o, g) => {
                const id = g.id();
                if (id) {
                  o[id] = g.nameSingular();
                }

                return o;
              }, {}),
              required: true,
            })}
            {this.submitButton()}
          </div>
        </div>
      </div>
    );
  }
}
