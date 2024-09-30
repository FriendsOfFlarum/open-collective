import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

export default class ExtensionSettingsPage extends ExtensionPage {
  content() {
    const useLegacySettingKey = 'fof-open-collective.use_legacy_api_key';
    const useLegacySetting = !!Number(this.setting(useLegacySettingKey)());

    return [
      <div className="container">
        <div className="OpenCollectiveSettings">
          <p>
            {app.translator.trans('fof-open-collective.admin.settings.desc', {
              a: <a href="https://opencollective.com/dashboard" target="_blank" />,
            })}
          </p>

          {this.buildSettingComponent({
            type: 'bool',
            setting: useLegacySettingKey,
            label: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_label'),
            help: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_help'),
          })}

          {this.buildSettingComponent({
            type: 'text',
            setting: 'fof-open-collective.api_key',
            label: app.translator.trans(`fof-open-collective.admin.settings.${useLegacySetting ? 'api_key' : 'personal_token'}_label`),
          })}

          {this.buildSettingComponent({
            type: 'text',
            setting: 'fof-open-collective.slug',
            label: app.translator.trans('fof-open-collective.admin.settings.slug_label'),
          })}

          <div className="Form-group">
            {this.buildSettingComponent({
              type: 'select',
              setting: 'fof-open-collective.group_id',
              options: app.store.all('groups').reduce((o, g) => {
                o[g.id()] = g.nameSingular();

                return o;
              }, {}),
              label: app.translator.trans('fof-open-collective.admin.settings.group_label'),
            })}
          </div>
          {this.submitButton()}
        </div>
      </div>,
    ];
  }
}
