<?php

namespace Kobo\JobTypes;

require_once __DIR__ . "/../KoboApi.php";

use Kobo\KoboApi;
use Kobo\Plugin;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;
use MapasCulturais\i;

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
                $app->log->error(i::__('KoboSyncJob: Dados de integração incompletos'));
                return false;
            }

            // Obtém a configuração do plugin
            $plugin = Plugin::getInstance();

            $api_config = $plugin->getApiConfig();
            
            if (empty($api_config['api_token'])) {
                $app->log->error(i::__('KoboSyncJob: Token da API do Kobo não configurado'));
                return false;
            }

            // Cria instância da API do Kobo
            $kobo_api = new KoboApi($api_config['api_url'], $api_config['api_token']);

            // Obtém os dados do formulário
            $app->log->info(i::__('KoboSyncJob: Buscando dados do formulário Kobo'));

            $submissions = $kobo_api->getSubmissions($kobo_form_id);

            $app->log->info(sprintf(i::__('KoboSyncJob: Encontrados %s registros no Kobo'), count($submissions)));

            // Processa cada submission
            $processed = 0;
            $errors = 0;

            foreach ($submissions as $submission) {
                try {
                    $this->processSubmission($submission, $target_entity, $field_mapping, $kobo_api, $kobo_form_id);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    $app->log->error(i::__('KoboSyncJob: Erro ao processar submission'));
                }
            }

            $app->log->info(i::__('KoboSyncJob: Sincronização concluída'));

            return true;

        } catch (\Exception $e) {
            $app->log->error(i::__('KoboSyncJob: Erro na execução do job'));
            $app->log->error(sprintf(i::__('Mensagem: %s'), $e->getMessage()));
            return false;
        }
    }

    protected function processSubmission(array $submission, string $target_entity, array $field_mapping, KoboApi $kobo_api, string $kobo_form_id)
    {
        $app = App::i();
        
        
        $submission_data = $submission;
        $kobo_submission_uuid = $submission['_uuid'] ?? $submission['_id'] ?? null;
        
        if (!$kobo_submission_uuid) {
            throw new \Exception(i::__('Submission sem identificador do formulário do Kobo'));
        }

        // Obtém o usuário que preencheu o formulário no Kobo
        if($kobo_username = $submission['_submitted_by'] ?? null) {
            $user_email = $kobo_api->getUserEmail($kobo_username);
            if ($user_email) {
                $user = $app->repo('User')->findOneBy(['email' => $user_email]);
                if (!$user) {
                    $app->log->error(i::__('KoboSyncJob: Usuário não encontrado no MapasCulturais'));
                    $app->log->error(sprintf(i::__('Email: %s'), $user_email));
                    return;
                }
            }
        }

        $this->updateOrCreateEntity($submission_data, $target_entity, $field_mapping, $user, $kobo_submission_uuid, $kobo_api);
    }

    protected function updateOrCreateEntity(array $submission_data, string $target_entity, array $field_mapping, $user, string $kobo_submission_uuid, KoboApi $kobo_api = null)
    {
        $app = App::i();

        try {
            $entity_class_name = $this->getEntityClassName($target_entity);

            $existing_entity = $this->findEntityByKoboSubmissionUuid($entity_class_name, $kobo_submission_uuid);

            $app->disableAccessControl();

            if ($existing_entity) {
                $entity = $existing_entity;
                $app->log->info(i::__('KoboSyncJob: Atualizando entidade existente'));
            } else {
                $entity = new $entity_class_name();

                if ($user) {
                    $entity->owner = $user->profile;
                }

                // Salva o UUID da submissão do Kobo
                $entity->kobo_submission_uuid = $kobo_submission_uuid;

                $app->log->info(i::__('KoboSyncJob: Criando nova entidade'));
            }

            // Mapeia os campos
            $this->mapFields($submission_data, $entity, $field_mapping, $kobo_api);
            $entity->save(true);

            $app->enableAccessControl();
        } catch (\Exception $e) {
            $app->enableAccessControl();

            throw $e;
        }
    }
    
    protected function getEntityClassName(string $target_entity): string
    {
        if (!class_exists($target_entity)) {
            throw new \Exception(sprintf(i::__("Classe de entidade %s não encontrada"), $target_entity));
        }

        return $target_entity;
    }


    protected function findEntityByKoboSubmissionUuid(string $entity_class_name, string $kobo_submission_uuid)
    {
        $app = App::i();
        
        // Busca a entidade completa usando DQL na tabela de metadados
        $dql = "SELECT e FROM {$entity_class_name} e 
                LEFT JOIN e.__metadata m 
                WITH m.key = 'kobo_submission_uuid' AND m.value = :kobo_submission_uuid 
                WHERE m.id IS NOT NULL";
        
        $query = $app->em->createQuery($dql);
        $query->setParameter('kobo_submission_uuid', $kobo_submission_uuid);
        $entity = $query->setMaxResults(1)->getOneOrNullResult();
        
        return $entity;
    }

    protected function mapFields(array $submission_data, $entity, array $field_mapping, KoboApi $kobo_api = null)
    {
        $app = App::i();
        
        foreach ($field_mapping as $kobo_field => $mapas_field) {
            $value = $submission_data[$kobo_field] ?? null;
            
            if ($value == null || $value == '') {
                continue;
            }
            
            // Verifica se é um campo especial (arquivo ou taxonomia)
            if ($this->isSpecialField($mapas_field)) {
                try {
                    $this->mapSpecialField($submission_data, $entity, $kobo_field, $mapas_field, $kobo_api);
                } catch (\Exception $e) {
                    $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao mapear campo especial %s: %s'), $kobo_field, $e->getMessage()));
                }
            } else {
                try {
                    $entity->$mapas_field = $value;
                } catch (\Exception $e) {
                    $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao atribuir campo %s: %s'), $mapas_field, $e->getMessage()));
                }
            }
        }
    }
    
    protected function isSpecialField(string $mapas_field): bool
    {
        $special_fields = ['avatar', 'gallery', 'imageGallery', 'videoGallery', 'videos'];
        return in_array($mapas_field, $special_fields) || str_starts_with($mapas_field, 'taxonomy:');
    }
    
    protected function mapSpecialField(array $submission_data, $entity, string $kobo_field, string $mapas_field, KoboApi $kobo_api = null)
    {
        // Mapeamento de arquivos (avatar, gallery, videos)
        // if (in_array($mapas_field, ['avatar', 'gallery', 'imageGallery', 'videoGallery', 'videos'])) {
        //     $this->mapFileField($submission_data, $entity, $kobo_field, $mapas_field, $kobo_api);
        // }

        if (str_starts_with($mapas_field, 'taxonomy:')) {
            $kobo_field_value = explode(' ', $submission_data[$kobo_field]);
            $mapas_field_value = explode(':', $mapas_field)[1];
            $entity->setTerms([$mapas_field_value => $kobo_field_value]);
        }
    }
}

