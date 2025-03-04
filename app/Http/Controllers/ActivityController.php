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

namespace App\Http\Controllers;

use App\Http\Requests\Activity\DownloadHistoricalEntityRequest;
use App\Http\Requests\Activity\ShowActivityRequest;
use App\Models\Activity;
use App\Transformers\ActivityTransformer;
use App\Utils\HostedPDF\NinjaPdf;
use App\Utils\Ninja;
use App\Utils\PhantomJS\Phantom;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\Pdf\PageNumbering;
use App\Utils\Traits\Pdf\PdfMaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use stdClass;

class ActivityController extends BaseController
{
    use PdfMaker, PageNumbering, MakesHash;

    protected $entity_type = Activity::class;

    protected $entity_transformer = ActivityTransformer::class;

    public function __construct()
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $default_activities = $request->has('rows') ? $request->input('rows') : 75;

        $activities = Activity::with('user')
                                ->orderBy('created_at', 'DESC')
                                ->company()
                                ->take($default_activities);
                                
        if($request->has('reactv2')) {

            /** @var \App\Models\User auth()->user() */
            $user = auth()->user();

            if (!$user->isAdmin()) {
                $activities->where('user_id', auth()->user()->id);
            }

            $system = ctrans('texts.system');

            $data = $activities->cursor()->map(function ($activity) {

                return $activity->activity_string();

            });

            return response()->json(['data' => $data->toArray()], 200);
        }

        return $this->listResponse($activities);
    }

    public function entityActivity(ShowActivityRequest $request)
    {

        $default_activities = request()->has('rows') ? request()->input('rows') : 75;

        $activities = Activity::with('user')
                                ->orderBy('created_at', 'DESC')
                                ->company()
                                ->where("{$request->entity}_id", $request->entity_id)
                                ->take($default_activities);

        /** @var \App\Models\User auth()->user() */
        $user = auth()->user();

        if (!$user->isAdmin()) {
            $activities->where('user_id', auth()->user()->id);
        }

        $system = ctrans('texts.system');

        $data = $activities->cursor()->map(function ($activity) {

            return $activity->activity_string();

        });

        return response()->json(['data' => $data->toArray()], 200);

    }

    public function downloadHistoricalEntity(DownloadHistoricalEntityRequest $request, Activity $activity)
    {
        $backup = $activity->backup;
        $html_backup = '';

        /* Refactor 20-10-2021
         *
         * We have moved the backups out of the database and into object storage.
         * In order to handle edge cases, we still check for the database backup
         * in case the file no longer exists
        */

        if ($backup && $backup->filename && Storage::disk(config('filesystems.default'))->exists($backup->filename)) { //disk
            if (Ninja::isHosted()) {
                $html_backup = file_get_contents(Storage::disk(config('filesystems.default'))->url($backup->filename));
            } else {
                $html_backup = file_get_contents(Storage::disk(config('filesystems.default'))->path($backup->filename));
            }
        } else { //failed
            return response()->json(['message'=> ctrans('texts.no_backup_exists'), 'errors' => new stdClass], 404);
        }

        if (config('ninja.phantomjs_pdf_generation') || config('ninja.pdf_generator') == 'phantom') {
            $pdf = (new Phantom)->convertHtmlToPdf($html_backup);

            $numbered_pdf = $this->pageNumbering($pdf, $activity->company);

            if ($numbered_pdf) {
                $pdf = $numbered_pdf;
            }
        } elseif (config('ninja.invoiceninja_hosted_pdf_generation') || config('ninja.pdf_generator') == 'hosted_ninja') {
            $pdf = (new NinjaPdf())->build($html_backup);

            $numbered_pdf = $this->pageNumbering($pdf, $activity->company);

            if ($numbered_pdf) {
                $pdf = $numbered_pdf;
            }
        } else {
            $pdf = $this->makePdf(null, null, $html_backup);

            $numbered_pdf = $this->pageNumbering($pdf, $activity->company);

            if ($numbered_pdf) {
                $pdf = $numbered_pdf;
            }
        }

        $activity->company->setLocale();
        
        if (isset($activity->invoice_id)) {
            $filename = $activity->invoice->numberFormatter().'.pdf';
        } elseif (isset($activity->quote_id)) {
            $filename = $activity->quote->numberFormatter().'.pdf';
        } elseif (isset($activity->credit_id)) {
            $filename = $activity->credit->numberFormatter().'.pdf';
        } else {
            $filename = 'backup.pdf';
        }

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}
