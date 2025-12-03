<?php

namespace Kobo;

use MapasCulturais\App;

require_once __DIR__ . "/JobTypes/KoboSyncJob.php";

class Plugin extends \MapasCulturais\Plugin
{
    protected static $instance;

    function __construct(array $config = [])
    {
        self::$instance = $this;

        $config += [
            'api_url' => 'https://kf.kobotoolbox.org/api/v2',
            'api_token' => '',
            'integrations' => []
        ];

        parent::__construct($config);
    }

    /**
     * @return void
     */
    function register(){}

    function _init() 
    {
        $app = App::i();
        $self = $this;

        $app->registerJobType(new JobTypes\KoboSyncJob(JobTypes\KoboSyncJob::SLUG));

        $self->scheduleSyncJobs();
    }

    public function scheduleSyncJobs()
    {
        $app = App::i();
        $integrations = $this->config['integrations'];
        
        if (!isset($integrations) || !is_array($integrations)) {
            return;
        }

        foreach ($integrations as $integration_id => $integration) {
            if (!isset($integration['enabled']) || !$integration['enabled']) {
                continue;
            }

            $job_data = [
                'integration_id' => $integration_id,
                'kobo_form_id' => $integration['kobo_form_id'],
                'target_entity' => $integration['target_entity'],
                'field_mapping' => $integration['field_mapping'] ?? []
            ];

            $interval_string = $integration['periodicity'] ?? '+1 day';
            
            $app->enqueueJob(
                JobTypes\KoboSyncJob::SLUG,
                $job_data,
                'now',
                $interval_string,
                1,
                true
            );
        }
    }

    static function getInstance()
    {
        return self::$instance;
    }

    public function getApiConfig()
    {
        return [
            'api_url' => $this->config['api_url'],
            'api_token' => $this->config['api_token'],
        ];
    }
}

