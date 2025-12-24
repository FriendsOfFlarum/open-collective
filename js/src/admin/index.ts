import app from 'flarum/admin/app';
import ExtensionSettingsPage from './components/ExtensionSettingsPage';

app.initializers.add('fof/open-collective', () => {
  app.registry.for('fof-open-collective').registerPage(ExtensionSettingsPage);
});
