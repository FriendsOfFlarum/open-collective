import ExtensionSettingsPage from './components/ExtensionSettingsPage';

app.initializers.add('fof/open-collective', () => {
    app.extensionData.for('fof-open-collective').registerPage(ExtensionSettingsPage);
});
