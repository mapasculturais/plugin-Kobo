<?php

namespace Kobo;

use MapasCulturais\App;
use MapasCulturais\i;

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
    function register() {
        $this->registerKoboMetadata();
    }

    function _init() 
    {
        $app = App::i();
        $self = $this;

        $app->registerJobType(new JobTypes\KoboSyncJob(JobTypes\KoboSyncJob::SLUG));
        $self->scheduleSyncJobs();
    }
    
    protected function registerKoboMetadata()
    {
        $integrations = $this->config['integrations'];
        
        if (!isset($integrations) || !is_array($integrations)) {
            return;
        }
        
        $entities = [];
        foreach ($integrations as $integration) {
            $target_entity = $integration['target_entity'] ?? null;
            if ($target_entity && !in_array($target_entity, $entities)) {
                $entities[] = $target_entity;
            }
        }
        
        foreach ($entities as $entity_class) {
            // Registra o metadado kobo_submission_uuid
            $this->registerMetadata($entity_class, 'kobo_submission_uuid', [
                'label' => i::__('UUID da Submissão Kobo'),
                'type' => 'string',
                'private' => true,
            ]);

            // Registra o metadado kobo_last_modified
            $this->registerMetadata($entity_class, 'kobo_last_modified', [
                'label' => i::__('Data da última sincronização Kobo'),
                'type' => 'datetime',
                'private' => true,
            ]);
        }
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
            
            // Agendar para meia-noite de hoje
            $midnight_today = date('Y-m-d 00:00:00');
            
            // Verificar se o job já está enfileirado
            $job_type = $app->getRegisteredJobType(JobTypes\KoboSyncJob::SLUG);
            $job_id = $job_type->generateId($job_data, $midnight_today, $interval_string, 10 * 365);
            
            if ($app->repo('Job')->findOneBy(['id' => $job_id])) {
                continue;
            }

            $app->enqueueJob(
                JobTypes\KoboSyncJob::SLUG,
                $job_data,
                $midnight_today,
                $interval_string,
                10 * 365,
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

