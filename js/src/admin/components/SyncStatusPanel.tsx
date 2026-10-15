import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/utils/humanTime';
import type Mithril from 'mithril';

export interface Credentials {
  apiKey: string;
  slug: string;
  useLegacyApiKey: boolean;
}

export interface SyncStatusPanelAttrs extends ComponentAttrs {
  /** The values currently in the form, which may not be saved yet. */
  credentials: () => Credentials;
}

interface LastSync {
  at: string;
  ok: boolean;
  kind: string | null;
  message: string | null;
  backers: number | null;
  changes: number | null;
}

type TestResult =
  | { ok: true; account: string | null; collective: string; orders: number; emailsVisible: boolean | null }
  | { ok: false; kind: string; message: string };

/** The sync runs hourly; anything older than this means it has stopped running. */
const STALE_AFTER_MS = 2 * 60 * 60 * 1000;

export default class SyncStatusPanel extends Component<SyncStatusPanelAttrs> {
  testing = false;
  result: TestResult | null = null;
  requestFailed = false;

  view(): Mithril.Children {
    return (
      <div className="Form-group OpenCollectiveStatus">
        <label>{app.translator.trans('fof-open-collective.admin.status.heading')}</label>
        {this.lastSyncView()}
        <Button className="Button" icon="fas fa-plug" loading={this.testing} onclick={() => this.test()}>
          {app.translator.trans('fof-open-collective.admin.status.test_button')}
        </Button>
        {this.resultView()}
      </div>
    );
  }

  lastSyncView(): Mithril.Children {
    const last = this.lastSync();

    if (!last) {
      return <Alert dismissible={false}>{app.translator.trans('fof-open-collective.admin.status.never_run')}</Alert>;
    }

    const time = humanTime(last.at);
    const stale = Date.now() - new Date(last.at).getTime() > STALE_AFTER_MS;

    return [
      last.ok ? (
        <Alert type="success" dismissible={false}>
          {app.translator.trans('fof-open-collective.admin.status.last_success', { time, backers: last.backers, changes: last.changes })}
        </Alert>
      ) : (
        <Alert type="error" dismissible={false}>
          {app.translator.trans('fof-open-collective.admin.status.last_failure', { time, reason: this.reason(last.kind) })}
          {last.message ? <p className="helpText">{last.message}</p> : null}
        </Alert>
      ),
      stale ? <Alert dismissible={false}>{app.translator.trans('fof-open-collective.admin.status.stale')}</Alert> : null,
    ];
  }

  resultView(): Mithril.Children {
    if (this.requestFailed) {
      return (
        <Alert type="error" dismissible={false}>
          {app.translator.trans('fof-open-collective.admin.status.request_failed')}
        </Alert>
      );
    }

    if (!this.result) return null;

    if (!this.result.ok) {
      return (
        <Alert type="error" dismissible={false}>
          {this.reason(this.result.kind)}
          <p className="helpText">{this.result.message}</p>
        </Alert>
      );
    }

    const { account, collective, orders, emailsVisible } = this.result;

    return (
      <Alert type="success" dismissible={false}>
        {emailsVisible === null
          ? app.translator.trans('fof-open-collective.admin.status.test_success_no_orders', { collective, account: account ?? '?' })
          : app.translator.trans('fof-open-collective.admin.status.test_success', { collective, account: account ?? '?', orders })}
      </Alert>
    );
  }

  reason(kind: string | null): Mithril.Children {
    switch (kind) {
      case 'unauthorized':
        return app.translator.trans('fof-open-collective.admin.status.reasons.unauthorized');
      case 'forbidden':
        return app.translator.trans('fof-open-collective.admin.status.reasons.forbidden');
      case 'emails_hidden':
        return app.translator.trans('fof-open-collective.admin.status.reasons.emails_hidden');
      case 'not_found':
        return app.translator.trans('fof-open-collective.admin.status.reasons.not_found');
      case 'rate_limited':
        return app.translator.trans('fof-open-collective.admin.status.reasons.rate_limited');
      case 'network':
        return app.translator.trans('fof-open-collective.admin.status.reasons.network');
      case 'configuration':
        return app.translator.trans('fof-open-collective.admin.status.reasons.configuration');
      case 'no_backers':
        return app.translator.trans('fof-open-collective.admin.status.reasons.no_backers');
      default:
        return app.translator.trans('fof-open-collective.admin.status.reasons.other');
    }
  }

  lastSync(): LastSync | null {
    const raw = app.data.settings['fof-open-collective.last_sync'];

    try {
      return raw ? (JSON.parse(raw) as LastSync) : null;
    } catch {
      return null;
    }
  }

  async test(): Promise<void> {
    this.testing = true;
    this.result = null;
    this.requestFailed = false;

    try {
      this.result = await app.request<TestResult>({
        method: 'POST',
        url: app.forum.attribute<string>('apiUrl') + '/fof/open-collective/test-connection',
        body: this.attrs.credentials(),
      });
    } catch {
      this.requestFailed = true;
    } finally {
      this.testing = false;
      m.redraw();
    }
  }
}
