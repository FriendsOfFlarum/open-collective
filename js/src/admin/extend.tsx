import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import Link from 'flarum/common/components/Link';
import Group from 'flarum/common/models/Group';

export default [
  new Extend.Admin() //
    .setting(() => ({
      type: 'bool',
      setting: 'fof-open-collective.use_legacy_api_key',
      label: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_label'),
      help: app.translator.trans('fof-open-collective.admin.settings.use_legacy_api_key_help'),
    }))
    .setting(() => {
      const useLegacySetting = !!Number(app.data.settings['fof-open-collective.use_legacy_api_key']);

      return {
        type: 'password',
        setting: 'fof-open-collective.api_key',
        label: app.translator.trans(`fof-open-collective.admin.settings.${useLegacySetting ? 'api_key' : 'personal_token'}_label`),
        help: app.translator.trans(`fof-open-collective.admin.settings.${useLegacySetting ? 'api_key' : 'personal_token'}_help`, {
          a: <Link href="https://opencollective.com/applications" target="_blank" />,
        }),
      };
    })
    .setting(() => ({
      type: 'string',
      setting: 'fof-open-collective.slug',
      label: app.translator.trans('fof-open-collective.admin.settings.slug_label'),
      help: app.translator.trans('fof-open-collective.admin.settings.slug_help'),
      required: true,
    }))
    .setting(() => ({
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
    }))
    .setting(() => ({
      type: 'select',
      setting: 'fof-open-collective.onetime_group_id',
      label: app.translator.trans('fof-open-collective.admin.settings.onetime_group_label'),
      help: app.translator.trans('fof-open-collective.admin.settings.onetime_group_help'),
      options: app.store.all<Group>('groups').reduce<Record<string, any>>((o, g) => {
        const id = g.id();
        if (id) {
          o[id] = g.nameSingular();
        }
        return o;
      }, {}),
    })),
];
