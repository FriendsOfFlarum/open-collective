import Component, { type ComponentAttrs } from 'flarum/common/Component';
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
type TestResult = {
    ok: true;
    account: string | null;
    collective: string;
    orders: number;
    emailsVisible: boolean | null;
} | {
    ok: false;
    kind: string;
    message: string;
};
export default class SyncStatusPanel extends Component<SyncStatusPanelAttrs> {
    testing: boolean;
    result: TestResult | null;
    requestFailed: boolean;
    view(): Mithril.Children;
    lastSyncView(): Mithril.Children;
    resultView(): Mithril.Children;
    reason(kind: string | null): Mithril.Children;
    lastSync(): LastSync | null;
    test(): Promise<void>;
}
export {};
