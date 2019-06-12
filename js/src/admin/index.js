import SettingsModal from '@fof/components/admin/settings/SettingsModal';
import StringItem from '@fof/components/admin/settings/items/StringItem';
import SelectItem from '@fof/components/admin/settings/items/SelectItem';

app.initializers.add('fof/open-collective', () => {
    app.extensionSettings['fof-open-collective'] = () =>
        app.modal.show(
            new SettingsModal({
                title: app.translator.trans('fof-open-collective.admin.settings.title'),
                size: 'medium',
                items: [
                    <p>{app.translator.trans('fof-open-collective.admin.settings.desc')}</p>,
                    <StringItem key="fof-open-collective.api_key" required type="password">
                        {app.translator.trans('fof-open-collective.admin.settings.api_key_label')}
                    </StringItem>,
                    <StringItem key="fof-open-collective.slug" required>
                        {app.translator.trans('fof-open-collective.admin.settings.slug_label')}
                    </StringItem>,
                    <div className="Form-group">
                        <label>{app.translator.trans('fof-open-collective.admin.settings.group_label')}</label>

                        {SelectItem.component({
                            options: app.store.all('groups').reduce((o, g) => {
                                o[g.id()] = g.nameSingular();

                                return o;
                            }, {}),
                            key: 'fof-open-collective.group_id',
                            required: true,
                        })}
                    </div>,
                ],
            })
        );
});
