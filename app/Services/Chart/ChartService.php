<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Chart;

use App\Models\Client;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ChartService
{
    use ChartQueries;

    public function __construct(public Company $company, private User $user, private bool $is_admin)
    {
    }

    /**
     * Returns an array of currencies that have
     * transacted with a company
     */
    public function getCurrencyCodes() :array
    {
        /* Get all the distinct client currencies */
        $currencies = Client::withTrashed()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', 0)
            ->when(!$this->is_admin, function ($query) {
                $query->where('user_id', $this->user->id);
            })
            ->distinct()
            ->pluck('settings->currency_id as id');

        /* Push the company currency on also */
        $currencies->push((int) $this->company->settings->currency_id);

        /* Add our expense currencies*/
        $expense_currencies = Expense::withTrashed()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', 0)
            ->when(!$this->is_admin, function ($query) {
                $query->where('user_id', $this->user->id);
            })
            ->distinct()
            ->pluck('currency_id as id');

        /* Merge and filter by unique */
        $currencies = $currencies->merge($expense_currencies)->unique();

        $cache_currencies = Cache::get('currencies');

        $filtered_currencies = $cache_currencies->whereIn('id', $currencies)->all();

        $final_currencies = [];

        foreach ($filtered_currencies as $c_currency) {
            $final_currencies[$c_currency['id']] = $c_currency['code'];
        }

        return $final_currencies;
    }

    /* Chart Data */
    public function chart_summary($start_date, $end_date) :array
    {
        $currencies = $this->getCurrencyCodes();

        $data = [];
        $data['start_date'] = $start_date;
        $data['end_date'] = $end_date;

        foreach ($currencies as $key => $value) {
            $data[$key]['invoices'] = $this->getInvoiceChartQuery($start_date, $end_date, $key);
            $data[$key]['outstanding'] = $this->getOutstandingChartQuery($start_date, $end_date, $key);
            $data[$key]['payments'] = $this->getPaymentChartQuery($start_date, $end_date, $key);
            $data[$key]['expenses'] = $this->getExpenseChartQuery($start_date, $end_date, $key);
        }

        return $data;
    }

    /* Chart Data */

    /* Totals */

    public function totals($start_date, $end_date) :array
    {
        $data = [];

        $data['currencies'] = $this->getCurrencyCodes();

        $data['start_date'] = $start_date;
        $data['end_date'] = $end_date;

        $revenue = $this->getRevenue($start_date, $end_date);
        $outstanding = $this->getOutstanding($start_date, $end_date);
        $expenses = $this->getExpenses($start_date, $end_date);
        $invoices = $this->getInvoices($start_date, $end_date);

        foreach ($data['currencies'] as $key => $value) {
           
            $invoices_set = array_search($key, array_column($invoices, 'currency_id'));
            $revenue_set = array_search($key, array_column($revenue, 'currency_id'));
            $outstanding_set = array_search($key, array_column($outstanding, 'currency_id'));
            $expenses_set = array_search($key, array_column($expenses, 'currency_id'));

            $data[$key]['invoices'] = $invoices_set !== false ? $invoices[array_search($key, array_column($invoices, 'currency_id'))] : new \stdClass;
            $data[$key]['revenue'] = $revenue_set !== false ? $revenue[array_search($key, array_column($revenue, 'currency_id'))] : new \stdClass;
            $data[$key]['outstanding'] = $outstanding_set !== false ? $outstanding[array_search($key, array_column($outstanding, 'currency_id'))] : new \stdClass;
            $data[$key]['expenses'] = $expenses_set !== false ? $expenses[array_search($key, array_column($expenses, 'currency_id'))] : new \stdClass;

        }

        return $data;
    }

    public function getInvoices($start_date, $end_date) :array
    {
        $revenue = $this->getInvoicesQuery($start_date, $end_date);
        $revenue = $this->addCurrencyCodes($revenue);

        return $revenue;
    }

    public function getRevenue($start_date, $end_date) :array
    {
        $revenue = $this->getRevenueQuery($start_date, $end_date);
        $revenue = $this->addCurrencyCodes($revenue);

        return $revenue;
    }

    public function getOutstanding($start_date, $end_date) :array
    {
        $outstanding = $this->getOutstandingQuery($start_date, $end_date);
        $outstanding = $this->addCurrencyCodes($outstanding);

        return $outstanding;
    }

    public function getExpenses($start_date, $end_date) :array
    {
        $expenses = $this->getExpenseQuery($start_date, $end_date);
        $expenses = $this->addCurrencyCodes($expenses);

        return $expenses;
    }

    /* Totals */

    /* Helpers */

    private function addCurrencyCodes($data_set) :array
    {
        $currencies = Cache::get('currencies');

        foreach ($data_set as $key => $value) {
            $data_set[$key]->currency_id = str_replace('"', '', $value->currency_id);
            $data_set[$key]->code = $this->getCode($currencies, $data_set[$key]->currency_id);
        }

        return $data_set;
    }

    private function getCode($currencies, $currency_id) :string
    {
        $currency_id = str_replace('"', '', $currency_id);

        $currency = $currencies->filter(function ($item) use ($currency_id) {
            return $item->id == $currency_id;
        })->first();

        if ($currency) {
            return $currency->code;
        }

        return '';
    }
}
