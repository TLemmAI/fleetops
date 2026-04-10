import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class OrderFormServiceRateComponent extends Component {
    @service serviceRateActions;
    @tracked selectedRate;
    @tracked serviceRates = [];
    @tracked serviceQuotes = [];

    /**
     * Sort mode for the hybrid rate comparison display.
     * 'price' = cheapest first (default), 'speed' = fastest first (lowest ETA).
     */
    @tracked sortBy = 'price';

    get isServicable() {
        return this.args.resource?.order_config && this.args.resource?.payloadCoordinates?.length >= 2;
    }

    /**
     * True when the order's facilitator is an IntegratedVendor (e.g.
     * ParcelPath / UPS Direct / USPS Direct). For these, the bridge layer
     * resolves rates server-side from the vendor's API instead of from
     * locally configured ServiceRate records, so the rate-selector
     * dropdown is hidden and quotes load directly when the toggle flips
     * on.
     */
    get isIntegratedVendorFacilitator() {
        return this.args.resource?.facilitator?.get?.('isIntegratedVendor') ?? false;
    }

    /**
     * True when any loaded quote carries carrier meta — i.e., the
     * quotes came from an IntegratedVendor bridge, not from internal
     * ServiceRate computation. Used to decide whether to render the
     * carrier-enriched display (logo, ETA, source badge, sort toggle)
     * or the classic public_id + breakdown table.
     */
    get hasCarrierQuotes() {
        return this.serviceQuotes?.some?.((q) => q.meta?.carrier) ?? false;
    }

    /**
     * Quotes sorted by the active sort mode. Only applies when carrier
     * meta is present; internal ServiceRate quotes have no meaningful
     * sort dimensions beyond the default DB order.
     */
    get sortedQuotes() {
        const quotes = this.serviceQuotes ?? [];
        if (!this.hasCarrierQuotes || quotes.length <= 1) {
            return quotes;
        }

        const sorted = [...quotes];
        if (this.sortBy === 'speed') {
            sorted.sort((a, b) => {
                const daysA = a.meta?.estimated_days ?? 999;
                const daysB = b.meta?.estimated_days ?? 999;
                if (daysA !== daysB) return daysA - daysB;
                // tie-break by price
                return (a.amount ?? 0) - (b.amount ?? 0);
            });
        } else {
            // 'price' — default
            sorted.sort((a, b) => {
                const amtA = a.amount ?? 0;
                const amtB = b.amount ?? 0;
                if (amtA !== amtB) return amtA - amtB;
                // tie-break by speed
                return (a.meta?.estimated_days ?? 999) - (b.meta?.estimated_days ?? 999);
            });
        }

        return sorted;
    }

    @action toggleSortBy() {
        this.sortBy = this.sortBy === 'price' ? 'speed' : 'price';
    }

    @task *queryServiceRates(toggled) {
        this.args.resource.servicable = toggled;
        if (!toggled) return;

        // Integrated-vendor path: skip the local ServiceRate query and fetch
        // quotes straight from the bound vendor (ParcelPath / UPS / USPS).
        if (this.isIntegratedVendorFacilitator) {
            yield this.getServiceQuotes.perform(null);
            return;
        }

        this.serviceRates = yield this.serviceRateActions.queryServiceRatesForOrder.perform(this.args.resource);
    }

    @task *getServiceQuotes(serviceRate) {
        this.selectedRate = serviceRate;
        this.serviceQuotes = yield this.serviceRateActions.getServiceQuotes.perform(serviceRate, this.args.resource);
    }
}
