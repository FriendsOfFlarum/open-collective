import app from 'flarum/admin/app';
import ExtensionSettingsPage from './components/ExtensionSettingsPage';

app.initializers.add('fof/open-collective', (app) => {
    app.extensionData.for('fof-open-collective').registerPage(ExtensionSettingsPage);
});
