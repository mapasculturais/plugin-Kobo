<?php

namespace Kobo\JobTypes;

use Kobo\KoboApi;
use Kobo\Plugin;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

class KoboSyncJob extends JobType
{
    const SLUG = "kobosync";

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        $integration_id = $data['integration_id'] ?? 'unknown';
        return "kobosync:{$integration_id}";
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        eval(\psy\sh());
        
        try {
            $integration_id = $job->integration_id ?? null;
            $kobo_form_id = $job->kobo_form_id ?? null;
            $target_entity = $job->target_entity ?? null;
            $field_mapping = $job->field_mapping ?? [];

            if (!$integration_id || !$kobo_form_id || !$target_entity) {
                $app->log->error("KoboSyncJob: Dados de integração incompletos", [
                    'integration_id' => $integration_id,
                    'kobo_form_id' => $kobo_form_id,
                    'target_entity' => $target_entity
                ]);
                return false;
            }

            // Obtém a configuração do plugin
            $plugin = Plugin::getInstance();

            $api_config = $plugin->getApiConfig();
            
            if (empty($api_config['api_token'])) {
                $app->log->error("KoboSyncJob: Token da API do Kobo não configurado");
                return false;
            }

            // Cria instância da API do Kobo
            $kobo_api = new KoboApi($api_config['api_url'], $api_config['api_token']);

            // Obtém os dados do formulário
            $app->log->info("KoboSyncJob: Buscando dados do formulário Kobo", [
                'form_id' => $kobo_form_id
            ]);

            $submissions = $kobo_api->getSubmissions($kobo_form_id);

            eval(\psy\sh());die;
            
            $app->log->info("KoboSyncJob: Encontrados " . count($submissions) . " registros no Kobo");

            // Processa cada submission
            $processed = 0;
            $errors = 0;

            foreach ($submissions as $submission) {
                try {
                    $this->processSubmission($submission, $target_entity, $field_mapping);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $app->log->error("KoboSyncJob: Erro ao processar submission", [
                        'submission_id' => $submission['_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $app->log->info("KoboSyncJob: Sincronização concluída", [
                'integration_id' => $integration_id,
                'processed' => $processed,
                'errors' => $errors
            ]);

            return true;

        } catch (\Exception $e) {
            $app->log->error("KoboSyncJob: Erro na execução do job", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    protected function processSubmission(array $submission, string $target_entity, array $field_mapping)
    {
        $app = App::i();
        
        $submission_data = $submission;
        $kobo_submission_uuid = $submission['_uuid'] ?? $submission['_id'] ?? null;
        
        if (!$kobo_submission_uuid) {
            throw new \Exception("Submission sem identificador único");
        }

        return;
    }
}

