<?php

namespace DTApi\Repository;

use DTApi\Events\{JobWasCreated, SessionEnded, JobWasCanceled};
use DTApi\Helpers\{SendSMSHelper, TeHelper, DateTimeHelper};
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\{Job, User, Language, UserMeta, Translator, UserLanguages, UsersBlacklist};
use Illuminate\Http\Request;
use DTApi\Mailers\{AppMailer, MailerInterface};
use Illuminate\Support\Facades\{DB, Auth, Log};
use Monolog\Handler\{StreamHandler, FirePHPHandler};

class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser         = User::findOrFail($user_id);
        $usertype      = '';
        $emergencyJobs = array();
        $noramlJobs    = array();

        if ($this->isUserIsCustomer($cuser)) {
            $jobs     = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
            $usertype = 'customer';
        } elseif ($this->isUserIsTranslator($cuser)) {
            $jobs     = Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->all();
            $usertype = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;

                    continue;
                }

                $noramlJobs[] = $jobitem;
            }

            $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return compact('emergencyJobs', 'noramlJobs', 'cuser', 'usertype');
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $cuser         = User::findOrFail($user_id);
        $pagenum       = $request->get('page', 1);
        $usertype      = '';
        $emergencyJobs = array();
        $noramlJobs    = array();

        if ($this->isUserIsCustomer($cuser)) {
            $jobs     = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $usertype = 'customer';

            return [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs'    => $noramlJobs,
                'jobs'          => $jobs,
                'cuser'         => $cuser,
                'usertype'      => $usertype,
                'numpages'      => 0,
                'pagenum'       => 0,
            ];
        }

        $jobs_ids   = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
        $totaljobs  = $jobs_ids->total();
        $numpages   = ceil($totaljobs / 15);
        $usertype   = 'translator';
        $jobs       = $jobs_ids;
        $noramlJobs = $jobs_ids;

        return compact('emergencyJobs', 'noramlJobs', 'jobs', 'cuser', 'usertype', 'numpages', 'pagenum');
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type !== env('CUSTOMER_ROLE_ID')) {
            return $this->createFailResponseStoreBooking('Translator can not create booking');
        }

        $cuser = $user;

        if (!isset($data['from_language_id'])) {
            return $this->createFailResponseStoreBooking('Du måste fylla in alla fält', 'from_language_id');
        }

        if ($this->isFieldIssetInArrayAndIsEmpty($data, 'duration')) {
            return $this->createFailResponseStoreBooking('Du måste fylla in alla fält', 'duration');
        }

        if ($data['immediate'] == 'no') {
            if ($this->isFieldIssetInArrayAndIsEmpty($data, 'due_date')) {
                return $this->createFailResponseStoreBooking('Du måste fylla in alla fält', 'due_date');
            }
            if ($this->isFieldIssetInArrayAndIsEmpty($data, 'due_time')) {
                return $this->createFailResponseStoreBooking('Du måste fylla in alla fält', 'due_time');
            }
            if ($this->isFieldIssetInArrayAndIsEmpty($data, 'customer_phone_type')) {
                return $this->createFailResponseStoreBooking('Du måste göra ett val här', 'customer_phone_type');
            }
            if ($this->isFieldIssetInArrayAndIsEmpty($data, 'duration')) {
                return $this->createFailResponseStoreBooking('Du måste fylla in alla fält', 'duration');
            }
        }

        $data[ 'customer_phone_type' ]        = (isset($data['customer_phone_type'])) ? 'yes' : 'no';
        $data[ 'customer_physical_type' ]     = (isset($data['customer_physical_type'])) ? 'yes' : 'no';
        $response[ 'customer_physical_type' ] = $data['customer_physical_type'];

        if ($data['immediate'] == 'yes') {
            $due_carbon                  = Carbon::now()->addMinute($immediatetime);
            $data['immediate']           = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type']            = 'immediate';

        } else {
            $due              = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon       = Carbon::createFromFormat('m/d/Y H:i', $due);

            if ($due_carbon->isPast()) {
                return $this->createFailResponseStoreBooking('Can\'t create booking in past');
            }
        }

        $data[ 'due' ]        = $due_carbon->format('Y-m-d H:i:s');
        $data[ 'gender' ]     = $this->getGenderByRequestJobFor($data[ 'job_for' ]);
        $data[ 'certified' ]  = $this->getCertifiedByRequestJobFor($data[ 'job_for' ]);
        $data['job_type']     = $this->getJobTypeByConsumerType($consumer_type);
        $data['b_created_at'] = $this->getCurrentDate();

        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        $job = $cuser->jobs()->create($data);

        $response['status'] = 'success';
        $response['id']     = $job->id;
        $data['job_for']    = array();

        if ($job->gender != null) {
            switch ($job->gender) {
                case 'male':
                    $data['job_for'][] = 'Man';
                    break;
                case 'female':
                    $data['job_for'][] = 'Kvinna';
                    break;
            }
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                    break;
                case 'yes':
                    $data['job_for'][] = 'certified';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }

        $data['customer_town'] = $cuser->userMeta->city;
        $data['customer_type'] = $cuser->userMeta->customer_type;

        return $response;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $job             = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference  = isset($data['reference']) ? $data['reference'] : '';
        $user            = $this->findFirstUserByJob($job);

        /**
            It will be refactored with refactored class Job

            class Job
            {
                //...

                public function setAddressFromRequest($requestData, UserMeta $userMeta)
                {
                    $this->address      = ($requestData[ 'address' ] != '') ? $requestData[ 'address' ] : $userMeta->address;
                    $this->instructions = ($requestData[ 'instructions' ] != '') ? $requestData[ 'instructions' ] : $userMeta->instructions;
                    $this->town         = ($requestData[ 'town' ] != '') ? $requestData[ 'town' ] : $userMeta->city;
                }

                // ...
            }
        */
        if (isset($data['address'])) {
            $job->setAddressFromRequest($data, $user->userMeta);
        }

        $job->save();

        $email   = $this->getJobEmailOrUserEmail($job, $user);
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $user->name, $subject, 'emails.job-created', $send_data);

        $response['type']   = $data['user_type'];
        $response['job']    = $job;
        $response['status'] = 'success';

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        foreach (['from_language_id', 'immediate', 'duration', 'status', 'gender', 'certified', 'due', 'job_type', 'customer_phone_type', 'customer_physical_type'] as $field) {
            $data[ $field ] = $job->$field;
        }

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();

        if ($job->gender != null) {
            switch ($job->gender) {
                case 'male':
                    $data['job_for'][] = 'Man';
                    break;
                case 'female':
                    $data['job_for'][] = 'Kvinna';
                    break;
            }
        }

        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }

        return $data;

    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completeddate     = $this->getCurrentDate();
        $job_detail        = $this->findJobWithTranslator($post_data[ 'job_id' ]);
        $interval          = $this->getDiffIntervalBetweenDates($job_detail->due, $completeddate);
        $job               = $job_detail;
        $job->end_at       = $completeddate;
        $job->status       = 'completed';
        $job->session_time = $interval;
        $user              = $this->findFirstUserByJob($job);
        $email             = $this->getJobEmailOrUserEmail($job, $user);
        $subject           = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode   = explode(':', $job->session_time);
        $session_time      = sprintf('%s tim %s min', $session_explode[ 0 ], $session_explode[ 1 ]);

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta    = UserMeta::where('user_id', $user_id)->first();
        $job_type     = $this->getJobTypeByUserMetaTranslatorType($user_meta->translator_type);
        $languages    = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $job_ids      = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $user_meta->gender, $user_meta->translator_level);

        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        $jobs = TeHelper::convertJobIdsInObjs($job_ids);

        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) {
                    continue;
                }

                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');

                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
                    continue;
                }

                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user

                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';

        if ($data['immediate'] == 'no') {
            $msg_contents = sprintf('Ny bokning för %stolk %smin %s', $data['language'], $data['duration'], $data['due']);
        } else {
            $msg_contents = sprintf('Ny akutbokning för %stolk %smin', $data['language'], $data['duration']);
        }

        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        /*
            It will be refactored with refactored class Job

            class Job
            {
                //...

                public function isOnlyCustromerPhysicalType()
                {
                    return ($this->customer_physical_type == 'yes' && $this->customer_phone_type == 'no')
                }

                public function isOnlyCustromerPhoneType()
                {
                    return ($this->customer_physical_type == 'no' && $this->customer_phone_type == 'yes')
                }

                public function isCustromerPhysicalTypeAndCustromerPhoneType()
                {
                    return ($this->customer_physical_type == 'yes' && $this->customer_phone_type == 'yes')
                }

                // ...
            }
        */
        if ($job->isOnlyCustromerPhysicalType()) {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->isOnlyCustromerPhoneType()) {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->isCustromerPhysicalTypeAndCustromerPhoneType()) {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');

        return ($not_get_nighttime == 'yes');
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        return ($not_get_notification != 'yes');
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound      = 'default';
        $android_sound  = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translator_type = $this->getTranslatorTypeByJobType($job->job_type);

        $joblanguage      = $job->from_language_id;
        $gender           = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            switch  ($job->certified) {
                case 'yes':
                case 'both':
                    $translator_level[] = 'Certified';
                    $translator_level[] = 'Certified with specialisation in law';
                    $translator_level[] = 'Certified with specialisation in health care';
                    break;
                case 'law':
                case 'n_law':
                    $translator_level[] = 'Certified with specialisation in law';
                    break;
                case 'health':
                case 'n_health':
                    $translator_level[] = 'Certified with specialisation in health care';
                    break;
                case 'normal':
                    $translator_level[] = 'Layman';
                    $translator_level[] = 'Read Translation courses';
                    break;
                default:
                    $translator_level[] = 'Certified';
                    $translator_level[] = 'Certified with specialisation in law';
                    $translator_level[] = 'Certified with specialisation in health care';
                    $translator_level[] = 'Layman';
                    $translator_level[] = 'Read Translation courses';
                    break;

            }
        }

        $blacklist     = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users         = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;

    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        }

        $job->save();

        if ($changeDue['dateChanged']) {
            $this->sendChangedDateNotification($job, $old_time);
        }

        if ($changeTranslator['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
        }

        if ($langChanged) {
            $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = $this->getJobEmailOrUserEmail($job, $user);
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = $this->getCurrentDate();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }

//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '') {

            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') {

                return false;
            }

            $interval          = $data['sesion_time'];
            $diff              = explode(':', $interval);
            $job->end_at       = $this->getCurrentDate();
            $job->session_time = $interval;
            $session_time      = sprintf('%s tim %s min', $diff[ 0 ], $diff[ 1 ]);
            $email             = $this->getJobEmailOrUserEmail($job, $user);
            $name              = $user->name;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email   = $user->user->email;
            $name    = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];

            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();

        $email = $this->getJobEmailOrUserEmail($job, $user);
        $name  = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = sprintf('Bekräftelse - tolk har accepterat er bokning (bokning # %s)', $job->id);
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        }

        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        $job->save();
        return true;


//        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes') {
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        } else {
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = $this->getJobEmailOrUserEmail($job, $user);
                $name  = $user->name;

                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user    = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $email   = $user->user->email;
                $name    = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }

            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $this->getJobEmailOrUserEmail($job, $user);
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $this->getJobEmailOrUserEmail($job, $user);
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $this->getJobEmailOrUserEmail($job, $user);
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = $this->jobToData($job);

        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {

        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $this->findFirstUserByJob($job);
                $mailer = new AppMailer();

                $email = $this->getJobEmailOrUserEmail($job, $user);
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);

            return [
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success',
            ];
        }

        return [
            'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
            'status'  => 'fail',
        ];
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $this->findFirstUserByJob($job);
                $mailer = new AppMailer();
                $email = $this->getJobEmailOrUserEmail($job, $user);
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $currentDate = $this->getCurrentDate();
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($this->isUserIsCustomer($cuser)) {
            $job->withdraw_at = Carbon::now();
            $job->status = ($job->withdraw_at->diffInHours($job->due) >= 24) ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }

            return [
                'status'  => 'success',
                'jobstatus' => 'success',
            ];
        }

        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $customer = $this->findFirstUserByJob($job);
            if ($customer) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                );
                if ($this->isNeedToSendPush($customer->id)) {
                    $users_array = array($customer);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                }
            }
            $job->status = 'pending';
            $job->created_at = $currentDate;
            $job->will_expire_at = TeHelper::willExpireAt($job->due, $currentDate);
            $job->save();
//                Event::fire(new JobWasCanceled($job));
            Job::deleteTranslatorJobRel($translator->id, $job_id);

            $data = $this->jobToData($job);

            $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators

            return ['status' => 'success'];
        }

        return [
            'status'  => 'fail',
            'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack',
        ];
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type   = 'unpaid';
        $job_type   = $this->getJobTypeByUserMetaTranslatorType($cuser_meta->translator_type);

        $languages        = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage     = collect($languages)->pluck('lang_id')->all();
        $gender           = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $currentDate = $this->getCurrentDate();
        $job_detail = $this->findJobWithTranslator($post_data[ 'job_id' ]);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $job               = $job_detail;
        $job->end_at       = $currentDate;
        $job->status       = 'completed';
        $job->session_time = $this->getDiffIntervalBetweenDates($job_detail->due, $currentDate);

        $user            = $this->findFirstUserByJob($job);
        $email           = $this->getJobEmailOrUserEmail($job, $user);
        $name            = $user->name;
        $subject         = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time    = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $currentDate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();

        return ['status' => 'success'];
    }


    public function customerNotCall($post_data)
    {
        $currentDate = $this->getCurrentDate();

        $job_detail  = $this->findJobWithTranslator($post_data[ 'job_id' ]);
        $interval    = $this->getDiffIntervalBetweenDates($job_detail->due, $currentDate);
        $job         = $job_detail;
        $job->end_at = $currentDate;
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $currentDate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();

        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            return $this->getAllJobsForSuperAdmin($requestdata, $limit);
        }

        return $this->getAllJobsForOther($requestdata, $limit);
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $currentDate = $this->getCurrentDate();
        $carbonNow = Carbon::now();

        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = array();
        $data['created_at']     = $currentDate;
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at']     = $currentDate;
        $data['user_id']        = $userid;
        $data['job_id']         = $jobid;
        $data['cancel_at']      = $carbonNow;

        $datareopen = array();
        $datareopen['status']         = 'pending';
        $datareopen['created_at']     = $carbonNow;
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at']         = $carbonNow;
            $job['updated_at']         = $carbonNow;
            $job['will_expire_at']     = TeHelper::willExpireAt($job['due'], $currentDate);
            $job['updated_at']         = $currentDate;
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);

            return ["Tolk cancelled!"];
        }

        return ["Please try again!"];
    }

    /**
     * Get all jobs for superadmin.
     *
     * @access private
     *
     * @param array  $requestdata Data from request.
     * @param string $limit       Limit jobs.
     *
     * @return
     */
    private function getAllJobsForSuperAdmin($requestdata, $limit)
    {
        $allJobs = Job::query();

        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });

            if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }
        }

        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            if (is_array($requestdata['id'])) {
                $allJobs->whereIn('id', $requestdata['id']);
            } else {
                $allJobs->where('id', $requestdata['id']);
            }

            $requestdata = array_only($requestdata, ['id']);
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('status', $requestdata['status']);
        }

        if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
            $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
        }

        if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
            $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
        }

        if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
            if ($users) {
                $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }

        if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('created_at', '>=', $requestdata["from"]);
            }

            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }

            $allJobs->orderBy('created_at', 'desc');
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
            /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
        }

        if (isset($requestdata['physical'])) {
            $allJobs->where('customer_physical_type', $requestdata['physical']);
            $allJobs->where('ignore_physical', 0);
        }

        if (isset($requestdata['phone'])) {
            $allJobs->where('customer_phone_type', $requestdata['phone']);
            if (isset($requestdata['physical'])) {
                $allJobs->where('ignore_physical_phone', 0);
            }
        }

        if (isset($requestdata['flagged'])) {
            $allJobs->where('flagged', $requestdata['flagged']);
            $allJobs->where('ignore_flagged', 0);
        }

        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }

        if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
            $allJobs = $allJobs->count();

            return ['count' => $allJobs];
        }

        if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
            $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }

        if (isset($requestdata['booking_type'])) {
            if ($requestdata['booking_type'] == 'physical') {
                $allJobs->where('customer_physical_type', 'yes');
            }

            if ($requestdata['booking_type'] == 'phone') {
                $allJobs->where('customer_phone_type', 'yes');
            }
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            return $allJobs->get();
        }

        return $allJobs->paginate(15);
    }

    /**
     * Get all jobs for other.
     *
     * @access private
     *
     * @param array  $requestdata Data from request.
     * @param string $limit       Limit jobs.
     *
     * @return
     */
    private function getAllJobsForOther($requestdata, $limit)
    {
        $allJobs = Job::query();

        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $allJobs->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if ($consumer_type == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function($q) {
                $q->where('rating', '<=', '3');
            });
            if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('status', $requestdata['status']);
        }
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', '=', $user->id);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            return $allJobs->get();
        }

        return $allJobs->paginate(15);
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    /**
     * Check is user is customer.
     *
     * @access private
     *
     * @param DTApi\Models\User $user User model.
     *
     * @return bool
     */
    private function isUserIsCustomer(User $user)
    {
        return $user->is('customer');
    }

    /**
     * Check is user is translator.
     *
     * @access private
     *
     * @param DTApi\Models\User $user User model.
     *
     * @return bool
     */
    private function isUserIsTranslator(User $user)
    {
        return $user->is('translator');
    }

    /**
     * Check if field isset in array and field is empty.
     *
     * @access private
     *
     * @param array  $array     Array data.
     * @param string $fieldName Field name.
     *
     * @return bool
     */
    private function isFieldIssetInArrayAndIsEmpty($array, $fieldName)
    {
        return (isset($array[ $fieldName ]) && $array[ $fieldName ] == '') ? true : false;
    }

    /**
     * Create fail response store booking.
     *
     * @access private
     *
     * @param string $message   Message text.
     * @param string $fieldName Field name.
     *
     * @return array
     */
    private function createFailResponseStoreBooking($message, $fieldName = '')
    {
        $response = [
            'status' => 'fail',
            'message' => $message,
        ];

        if (!empty($fieldName)) {
            $response[ 'field_name' ] = $fieldName;
        }

        return $response;
    }

    /**
     * Get gender by request job for,
     *
     * @access private
     *
     * @param array $arrayJobFor Request job for data.
     *
     * @return string|null
     */
    private function getGenderByRequestJobFor($arrayJobFor)
    {
        if (in_array('male', $arrayJobFor)) {
            return 'male';
        } else if (in_array('female', $arrayJobFor)) {
            return 'female';
        }

        return null;
    }

    /**
     * Get certified by request job for,
     *
     * @access private
     *
     * @param array $arrayJobFor Request job for data.
     *
     * @return string|null
     */
    private function getCertifiedByRequestJobFor($arrayJobFor)
    {
        if (in_array('normal', $arrayJobFor) && in_array('certified', $arrayJobFor)) {
            return 'both';
        } else if(in_array('normal', $arrayJobFor) && in_array('certified_in_law', $arrayJobFor)) {
            return 'n_law';
        } else if(in_array('normal', $arrayJobFor) && in_array('certified_in_helth', $arrayJobFor)) {
            return 'n_health';
        }

        if (in_array('normal', $arrayJobFor)) {
            return 'normal';
        } else if (in_array('certified', $arrayJobFor)) {
            return 'yes';
        } else if (in_array('certified_in_law', $arrayJobFor)) {
            return 'law';
        } else if (in_array('certified_in_helth', $arrayJobFor)) {
            return'health';
        }

        return null;
    }

    /**
     * Get job type by consumer type.
     *
     * @access private
     *
     * @param string $consumerType Consumer type.
     *
     * @return string|null
     */
    private function getJobTypeByConsumerType($consumerType)
    {
        switch ($consumerType) {
            case 'rwsconsumer':
                return 'rws';
            case 'ngo':
                return 'unpaid';
            case 'paid':
                return 'paid';
        }

        return null;
    }

    /**
     * Get job type by user meta translator type.
     *
     * @access private
     *
     * @param string $translatorType Translator type.
     *
     * @return string
     */
    private function getJobTypeByUserMetaTranslatorType($translatorType)
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid'; /*show all jobs for professionals.*/
            case 'rwstranslator':
                return 'rws'; /* for rwstranslator only show rws jobs. */
            default:
                return 'unpaid'; /* for volunteers only show unpaid jobs. */
        }
    }

    /**
     * Get translator type by job type.
     *
     * @access private
     *
     * @param string $jobType Job type.
     *
     * @return string|null
     */
    private function getTranslatorTypeByJobType($jobType)
    {
        switch ($jobType) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
        }

        return null;
    }

    /**
     * Get email by job email or user email.
     *
     * @access private
     *
     * @param
     * @param
     *
     * @return string
     */
    private function getJobEmailOrUserEmail($job, $user)
    {
        return (!empty($job->user_email)) ? $job->user_email : $user->email;
    }

    /**
     * Find job with translator by job id.
     *
     * @access private
     *
     * @param int $jobId Job id.
     *
     * @return
     */
    private function findJobWithTranslator($jobId)
    {
        return Job::with('translatorJobRel')->find($jobId);
    }

    /**
     * Get diff interval between two dates.
     *
     * @access private
     *
     * @param string $start Start date,
     * @param string $end   End date.
     *
     * @return string
     */
    private function getDiffIntervalBetweenDates($start, $end)
    {
        $start = date_create($start);
        $end   = date_create($end);
        $diff  = date_diff($end, $start);

        return sprintf('%s:%s:%s', $diff->h, $diff->i, $diff->s);
    }

    /**
     * Find first user by job.
     *
     * @access private
     *
     * @param
     *
     * @return
     */
    private function findFirstUserByJob(Job $job)
    {
        return $job->user()->get()->first();
    }

    /**
     * Get current date.
     *
     * @access private
     *
     * @return string
     */
    private function getCurrentDate()
    {
        return date('Y-m-d H:i:s');
    }
}