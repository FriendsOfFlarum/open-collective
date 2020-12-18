import ExtensionPage from 'flarum/components/ExtensionPage';
import { settings } from '@fof-components';

const {
    items: { StringItem, SelectItem },
} = settings;

export default class ExtensionSettingsPage extends ExtensionPage {
    oninit(vnode) {
        super.oninit(vnode);

        this.setting = this.setting.bind(this);
    }

    content() {
        return [
            <div className="container">
                <div className="OpenCollectiveSettings">
                    <p>
                        {app.translator.trans('fof-open-collective.admin.settings.desc', {
                            a: <a href="https://opencollective.com/applications" target="_blank" />,
                        })}
                    </p>
                    <StringItem setting={this.setting} name="fof-open-collective.api_key" required type="password">
                        {app.translator.trans('fof-open-collective.admin.settings.api_key_label')}
                    </StringItem>
                    <StringItem setting={this.setting} name="fof-open-collective.slug" required>
                        {app.translator.trans('fof-open-collective.admin.settings.slug_label')}
                    </StringItem>
                    <div className="Form-group">
                        <label>{app.translator.trans('fof-open-collective.admin.settings.group_label')}</label>

                        {SelectItem.component({
                            options: app.store.all('groups').reduce((o, g) => {
                                o[g.id()] = g.nameSingular();

                                return o;
                            }, {}),
                            name: 'fof-open-collective.group_id',
                            required: true,
                            setting: this.setting,
                        })}
                    </div>
                    {this.submitButton()}
                </div>
            </div>,
        ];
    }
}
