import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Minimal batch shipping component for Phase 3.
 *
 * Flow: CSV upload → preview → rate → select → purchase → download.
 *
 * All API calls go through the existing fetch service to hit
 * POST /v1/batch-shipments/rates and POST /v1/batch-shipments/purchase.
 */
export default class BatchShippingComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked step = 'upload'; // upload | preview | rated | purchasing | done
    @tracked rows = [];
    @tracked rateResults = [];
    @tracked purchaseResults = [];
    @tracked isLoading = false;

    /**
     * Parse a CSV file into shipment rows. Expects columns:
     * row_id, pickup, dropoff, length, width, height, weight, facilitator
     */
    @action async handleFileUpload(event) {
        const file = event.target?.files?.[0];
        if (!file) return;

        const text = await file.text();
        const lines = text.trim().split('\n');
        const headers = lines[0].split(',').map((h) => h.trim().toLowerCase());

        const parsed = [];
        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(',').map((c) => c.trim());
            const row = {};
            headers.forEach((h, idx) => (row[h] = cols[idx] ?? ''));

            parsed.push({
                row_id: row.row_id || `row_${i - 1}`,
                pickup: row.pickup,
                dropoff: row.dropoff,
                parcels: [
                    {
                        length: parseFloat(row.length) || 0,
                        width: parseFloat(row.width) || 0,
                        height: parseFloat(row.height) || 0,
                        weight: parseFloat(row.weight) || 0,
                    },
                ],
                facilitator: row.facilitator || null,
                _rates: null,
                _selectedQuoteUuid: null,
                _purchaseResult: null,
            });
        }

        this.rows = parsed;
        this.step = 'preview';
    }

    @action async rateAll() {
        this.isLoading = true;
        try {
            const shipments = this.rows.map((r) => ({
                row_id: r.row_id,
                pickup: r.pickup,
                dropoff: r.dropoff,
                parcels: r.parcels,
                facilitator: r.facilitator,
            }));

            const response = await this.fetch.post('batch-shipments/rates', { shipments });
            const results = response?.results ?? [];

            // Merge rate results back into rows by row_id.
            const byId = {};
            results.forEach((r) => (byId[r.row_id] = r));

            this.rows = this.rows.map((row) => {
                const result = byId[row.row_id];
                return {
                    ...row,
                    _rates: result?.rates ?? [],
                    _rateError: result?.status === 'error' ? result.error : null,
                    _selectedQuoteUuid: result?.rates?.[0]?.uuid ?? null, // default: cheapest (first)
                };
            });

            this.step = 'rated';
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.isLoading = false;
        }
    }

    @action selectQuote(row, quoteUuid) {
        row._selectedQuoteUuid = quoteUuid;
        // Trigger re-render by replacing the array.
        this.rows = [...this.rows];
    }

    @action async purchaseAll() {
        this.isLoading = true;
        this.step = 'purchasing';
        try {
            const purchases = this.rows
                .filter((r) => r._selectedQuoteUuid)
                .map((r) => ({
                    row_id: r.row_id,
                    service_quote_uuid: r._selectedQuoteUuid,
                }));

            const response = await this.fetch.post('batch-shipments/purchase', { purchases });
            const results = response?.results ?? [];

            const byId = {};
            results.forEach((r) => (byId[r.row_id] = r));

            this.rows = this.rows.map((row) => ({
                ...row,
                _purchaseResult: byId[row.row_id] ?? null,
            }));

            this.purchaseResults = results;
            this.step = 'done';
        } catch (err) {
            this.notifications.serverError(err);
            this.step = 'rated'; // allow retry
        } finally {
            this.isLoading = false;
        }
    }

    @action reset() {
        this.rows = [];
        this.rateResults = [];
        this.purchaseResults = [];
        this.step = 'upload';
    }
}
