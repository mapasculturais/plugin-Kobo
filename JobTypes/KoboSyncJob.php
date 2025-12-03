<?php

namespace Kobo\JobTypes;

require_once __DIR__ . "/../KoboApi.php";

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
        
        try {
            $integration_id = $job->integration_id ?? null;
            $kobo_form_id = $job->kobo_form_id ?? null;
            $target_entity = $job->target_entity ?? null;
            $field_mapping = $job->field_mapping ?? [];

            if (!$integration_id || !$kobo_form_id || !$target_entity) {
                $app->log->error("KoboSyncJob: Dados de integração incompletos");
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
            $app->log->info("KoboSyncJob: Buscando dados do formulário Kobo");

            $submissions = $kobo_api->getSubmissions($kobo_form_id);

            $app->log->info("KoboSyncJob: Encontrados " . count($submissions) . " registros no Kobo");

            // Processa cada submission
            $processed = 0;
            $errors = 0;

            foreach ($submissions as $submission) {
                try {
                    $this->processSubmission($submission, $target_entity, $field_mapping, $kobo_api);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $app->log->error("KoboSyncJob: Erro ao processar submission");
                }
            }

            $app->log->info("KoboSyncJob: Sincronização concluída");

            return true;

        } catch (\Exception $e) {
            $app->log->error("KoboSyncJob: Erro na execução do job");
            return false;
        }
    }

    protected function processSubmission(array $submission, string $target_entity, array $field_mapping, KoboApi $kobo_api)
    {
        $app = App::i();
        
        
        $submission_data = $submission;
        $kobo_submission_uuid = $submission['_uuid'] ?? $submission['_id'] ?? null;
        
        if (!$kobo_submission_uuid) {
            throw new \Exception("Submission sem identificador único");
        }

        // Obtém o usuário que preencheu o formulário no Kobo
        $kobo_username = $submission['_submitted_by'] ?? null;
        // $user = null;
 
        // TODO: Ajustar função getUserFromKobo após ter permissão de admin no Kobo
        $user = $app->repo('User')->findOneBy(['id' => 1]);
        // if($kobo_username) {
        //     $kobo_user = $kobo_api->getUserFromKobo($kobo_username);
        //     $user_email = $kobo_user['email'] ?? null;
        //     if ($user_email) {
        //         $user = $app->repo('User')->findOneBy(['email' => $user_email]);
        //     }
        // }
        $this->updateOrCreateEntity($submission_data, $target_entity, $field_mapping, $user, $kobo_submission_uuid, $kobo_username);

    }
    
    protected function updateOrCreateEntity(array $submission_data, string $target_entity, array $field_mapping, $user, string $kobo_submission_uuid, ?string $kobo_username)
    {
        $app = App::i();
        
        $entity_class_name = $this->getEntityClassName($target_entity);

        // possibilidade de fazer com metadado para encontrar a entidade
        // $existing_entity = $this->findEntityByKoboSubmissionUuid($entity_class_name, $kobo_submission_uuid);
        $existing_entity = null;
        
        $app->disableAccessControl();

        if ($existing_entity) {
            $entity = $existing_entity;
            $app->log->info("KoboSyncJob: Atualizando entidade existente");
        } else {
            $entity = new $entity_class_name();
            
            if ($user) {
                $entity->owner = $user->profile;
            }
            
            $app->log->info("KoboSyncJob: Criando nova entidade");
        }

        // Caso utilize metadado
        // $entity->kobo_submission_uuid = $kobo_submission_uuid;
        // if ($kobo_username) {
        //     $entity->kobo_submitted_by = $kobo_username;
        // }
        // if (isset($submission_data['_id'])) {
        //     $entity->kobo_submission_id = $submission_data['_id'];
        // }

        // Mapeia os campos
        $this->mapFields($submission_data, $entity, $field_mapping);

        $entity->save(true);

        $app->enableAccessControl();
    }
    
    protected function getEntityClassName(string $target_entity): string
    {
        // Se já tem namespace completo, usa direto
        if (strpos($target_entity, '\\') !== false) {
            return $target_entity;
        }
        
        // Tenta primeiro como entidade padrão do Mapas
        $entity_class_name = "\MapasCulturais\Entities\\{$target_entity}";
        
        // Se não existir, tenta como entidade custom (CustomEntity)
        if (!class_exists($entity_class_name)) {
            $custom_entity_class = "CustomEntity\Entities\\{$target_entity}";
            if (class_exists($custom_entity_class)) {
                return $custom_entity_class;
            } else {
                throw new \Exception("Classe de entidade '{$target_entity}' não encontrada em MapasCulturais\\Entities nem em CustomEntity\\Entities");
            }
        }
        
        return $entity_class_name;
    }


    protected function findEntityByKoboSubmissionUuid(string $entity_class_name, string $kobo_submission_uuid)
    {
        $app = App::i();

        $repo_entity_name = $entity_class_name;
        if (strpos($entity_class_name, 'CustomEntity\\Entities\\') === 0) {
        } else {
            $repo_entity_name = str_replace('MapasCulturais\\Entities\\', '', $entity_class_name);
        }
        
        $entity = $app->repo($repo_entity_name)->findOneBy(['kobo_submission_uuid' => $kobo_submission_uuid]);

        if ($entity) {
            return $entity;
        }

        return null;
    }

    protected function mapFields(array $submission_data, $entity, array $field_mapping)
    {
        foreach ($field_mapping as $kobo_field => $mapas_field) {
            if ($value = $submission_data[$kobo_field] ?? null) {
                $entity->$mapas_field = $value;
            }
        }
    }
}

