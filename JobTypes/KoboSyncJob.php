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

            // Obtém a data da última sincronização baseada nas entidades já sincronizadas
            $last_sync_time = $this->getLastSyncTime($target_entity);
            
            // Obtém os dados do formulário
            $app->log->info(i::__('KoboSyncJob: Buscando dados do formulário Kobo'));
            
            if ($last_sync_time) {
                $app->log->info(sprintf(i::__('KoboSyncJob: Última sincronização: %s'), $last_sync_time->format('Y-m-d H:i:s')));
            }

            $submissions = $kobo_api->getSubmissions($kobo_form_id);

            // Filtra apenas submissions modificados desde a última sincronização
            $submissions_to_process = $this->filterSubmissionsByDate($submissions, $last_sync_time);

            $app->log->info(sprintf(i::__('KoboSyncJob: Encontrados %s registros no Kobo, %s para processar'), count($submissions), count($submissions_to_process)));

            // Processa cada submission
            $processed = 0;
            $errors = 0;

            foreach ($submissions_to_process as $submission) {
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

            // Salva a data de modificação (end) do submission na entidade
            $submission_end = $this->getSubmissionEndTime($submission_data);
            if ($submission_end) {
                $entity->kobo_last_modified = $submission_end->format('Y-m-d H:i:s');
            }

            // Mapeia os campos
            $this->mapFields($submission_data, $entity, $field_mapping, $kobo_api);
            $entity->save(true);
            
            // Processa arquivos após salvar a entidade (precisa de ID)
            $this->processFileFields($submission_data, $entity, $field_mapping, $kobo_api);

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

    protected function getLastSyncTime(string $target_entity): ?\DateTime
    {
        $app = App::i();
        
        try {
            $entity_class_name = $this->getEntityClassName($target_entity);
            
            // Busca a entidade com o kobo_last_modified mais recente
            $dql = "SELECT e FROM {$entity_class_name} e 
                    LEFT JOIN e.__metadata m 
                    WITH m.key = 'kobo_last_modified' 
                    WHERE m.id IS NOT NULL 
                    ORDER BY m.value DESC";
            
            $query = $app->em->createQuery($dql);
            $query->setMaxResults(1);
            $entity = $query->getOneOrNullResult();
            
            if ($entity && isset($entity->kobo_last_modified)) {
                try {
                    return new \DateTime($entity->kobo_last_modified);
                } catch (\Exception $e) {
                    return null;
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        
        return null;
    }
    
    protected function filterSubmissionsByDate(array $submissions, ?\DateTime $last_sync_time): array
    {
        // Se não há última sincronização, processa todos
        if (!$last_sync_time) {
            return $submissions;
        }
        
        $filtered = [];
        foreach ($submissions as $submission) {
            $submission_end = $this->getSubmissionEndTime($submission);
            
            // Se não tem data de fim ou é mais recente que a última sincronização, processa
            if (!$submission_end || $submission_end > $last_sync_time) {
                $filtered[] = $submission;
            }
        }
        
        return $filtered;
    }
    
    protected function getSubmissionEndTime(array $submission): ?\DateTime
    {
        if (isset($submission['end'])) {
            return new \DateTime($submission['end']);
        }
        
        // Se 'end' não estiver disponível
        if (isset($submission['_submission_time'])) {
            return new \DateTime($submission['_submission_time']);
        }
        
        return null;
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
                $file_fields = ['avatar', 'gallery', 'imageGallery', 'videoGallery', 'videos'];
                if (in_array($mapas_field, $file_fields)) {
                    continue;
                }
                
                try {
                    $this->mapSpecialField($submission_data, $entity, $kobo_field, $mapas_field, $kobo_api);
                } catch (\Exception $e) {
                    $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao mapear campo especial %s: %s'), $kobo_field, $e->getMessage()));
                }
            } else {
                try {
                    // Tratamento para location
                    if ($mapas_field === 'location' || $mapas_field === 'geoLocation') {
                        $entity->$mapas_field = $this->parseLocation($value);
                    } else {
                        $entity->$mapas_field = $value;
                    }
                } catch (\Exception $e) {
                    $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao atribuir campo %s: %s'), $mapas_field, $e->getMessage()));
                }
            }
        }
    }
    
    protected function processFileFields(array $submission_data, $entity, array $field_mapping, KoboApi $kobo_api = null)
    {
        $app = App::i();
        
        foreach ($field_mapping as $kobo_field => $mapas_field) {
            $value = $submission_data[$kobo_field] ?? null;
            
            if ($value == null || $value == '') {
                continue;
            }
            
            // Processa apenas campos de arquivo
            $file_fields = ['avatar', 'gallery', 'imageGallery', 'videoGallery', 'videos'];
            if (in_array($mapas_field, $file_fields)) {
                try {
                    $this->mapSpecialField($submission_data, $entity, $kobo_field, $mapas_field, $kobo_api);
                } catch (\Exception $e) {
                    $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao mapear campo de arquivo %s: %s'), $kobo_field, $e->getMessage()));
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
        $file_fields = ['avatar', 'gallery', 'imageGallery', 'videoGallery', 'videos'];
        if (in_array($mapas_field, $file_fields)) {
            $this->mapFileField($submission_data, $entity, $kobo_field, $mapas_field, $kobo_api);
            return;
        }

        // Mapeamento de taxonomias
        if (str_starts_with($mapas_field, 'taxonomy:')) {
            $kobo_field_value = explode(' ', $submission_data[$kobo_field]);
            $mapas_field_value = explode(':', $mapas_field)[1];
            $entity->setTerms([$mapas_field_value => $kobo_field_value]);
        }
    }
    
    protected function mapFileField(array $submission_data, $entity, string $kobo_field, string $mapas_field, KoboApi $kobo_api = null)
    {
        $app = App::i();
        
        if (!$kobo_api) {
            $app->log->warning(i::__('KoboSyncJob: KoboApi não disponível para baixar arquivos'));
            return;
        }
        
        $attachments = $submission_data['_attachments'] ?? [];
        
        // Determina o grupo do arquivo
        $file_group = $this->getFileGroup($mapas_field);
        
        try {
            // Para avatar
            if ($mapas_field === 'avatar') {
                $this->saveSingleFile($entity, $kobo_field, $attachments, $file_group, $kobo_api);
            }
            // Para galeria de imagens
            elseif (in_array($mapas_field, ['gallery', 'imageGallery'])) {
                $this->saveImageGallery($entity, $kobo_field, $attachments, $file_group, $kobo_api);
            }
            // Para vídeos
            elseif (in_array($mapas_field, ['videoGallery', 'videos'])) {
                $this->saveVideoGallery($entity, $kobo_field, $attachments, $file_group, $kobo_api);
            }
        } catch (\Exception $e) {
            $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao processar arquivo %s: %s'), $kobo_field, $e->getMessage()));
        }
    }
    
    protected function getFileGroup(string $mapas_field): string
    {
        $group_mapping = [
            'avatar' => 'avatar',
            'gallery' => 'gallery',
            'imageGallery' => 'gallery',
            'videoGallery' => 'videos',
            'videos' => 'videos',
        ];
        
        return $group_mapping[$mapas_field] ?? 'gallery';
    }
    
    protected function saveSingleFile($entity, string $kobo_field, array $attachments, string $file_group, KoboApi $kobo_api)
    {
        $app = App::i();
        $app->log->info(sprintf(i::__('KoboSyncJob: Procurando arquivo para campo: %s (grupo: %s)'), $kobo_field, $file_group));
        $app->log->info(sprintf(i::__('KoboSyncJob: Entidade ID: %s, Tipo: %s'), $entity->id ?? 'NOVO', get_class($entity)));
        
        $found = false;
        foreach ($attachments as $attachment) {
            $question_xpath = $attachment['question_xpath'] ?? '';
            
            // Remove índices do question_xpath para comparar (ex: grupo_imagens[1]/imagens -> grupo_imagens/imagens)
            $normalized_xpath = preg_replace('/\[\d+\]/', '', $question_xpath);
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Verificando attachment - question_xpath: %s, normalized: %s, kobo_field: %s'), $question_xpath, $normalized_xpath, $kobo_field));
            
            if ($normalized_xpath == $kobo_field || $question_xpath == $kobo_field) {
                $app->log->info(sprintf(i::__('KoboSyncJob: Attachment encontrado! URL: %s'), ($attachment['download_url'] ?? 'N/A')));
                $found = true;
                
                // Remove arquivos existentes do grupo
                $existing_files = $entity->getFiles($file_group);
                if (is_array($existing_files)) {
                    $app->log->info(sprintf(i::__('KoboSyncJob: Removendo %d arquivo(s) existente(s) do grupo %s'), count($existing_files), $file_group));
                    foreach ($existing_files as $file) {
                        $file->delete(true);
                    }
                } elseif ($existing_files) {
                    $app->log->info(sprintf(i::__('KoboSyncJob: Removendo arquivo existente do grupo %s'), $file_group));
                    $existing_files->delete(true);
                }
                
                $this->downloadAndSaveFile($entity, $attachment, $file_group, $kobo_api);
                break;
            }
        }
        
        if (!$found) {
            $app->log->warning(sprintf(i::__('KoboSyncJob: Nenhum attachment encontrado para o campo %s'), $kobo_field));
        }
    }
    
    protected function saveImageGallery($entity, string $kobo_field, array $attachments, string $file_group, KoboApi $kobo_api)
    {
        // Procura todos os attachments que correspondem ao grupo_imagens
        $image_attachments = [];
        foreach ($attachments as $attachment) {
            $question_xpath = $attachment['question_xpath'] ?? '';
            $mimetype = $attachment['mimetype'] ?? '';
            
            // Verifica se é uma imagem
            if (strpos($mimetype, 'image/') !== 0) {
                continue;
            }
            
            // Normaliza o question_xpath removendo índices (ex: grupo_imagens[1]/imagens -> grupo_imagens/imagens)
            $normalized_xpath = preg_replace('/\[\d+\]/', '', $question_xpath);
            
            // Verifica se corresponde ao campo de imagens
            if (strpos($normalized_xpath, $kobo_field) === 0 || 
                strpos($question_xpath, $kobo_field) === 0) {
                $image_attachments[] = $attachment;
            }
        }
        
        // Remove arquivos antigos da galeria
        // $existing_files = $entity->getFiles($file_group);
        // if (is_array($existing_files)) {
        //     foreach ($existing_files as $file) {
        //         $file->delete(true);
        //     }
        // }
        
        // Baixa e salva cada imagem
        foreach ($image_attachments as $attachment) {
            $this->downloadAndSaveFile($entity, $attachment, $file_group, $kobo_api);
        }
    }
    
    protected function saveVideoGallery($entity, string $kobo_field, array $attachments, string $file_group, KoboApi $kobo_api)
    {
        $app = App::i();
        
        $video_attachments = [];
        foreach ($attachments as $attachment) {
            $question_xpath = $attachment['question_xpath'] ?? '';
            $mimetype = $attachment['mimetype'] ?? '';
            
            // Verifica se é um vídeo
            if (strpos($mimetype, 'video/') !== 0) {
                continue;
            }
            
            // Normaliza o question_xpath removendo índices se houver
            $normalized_xpath = preg_replace('/\[\d+\]/', '', $question_xpath);
            
            // Verifica se corresponde ao campo de vídeos
            if (strpos($normalized_xpath, $kobo_field) === 0 || 
                strpos($question_xpath, $kobo_field) === 0) {
                $video_attachments[] = $attachment;
            }
        }
        
        // Baixa e salva cada vídeo como arquivo, depois cria MetaList
        foreach ($video_attachments as $attachment) {
            try {
                // Primeiro salva o arquivo de vídeo
                $video_file = $this->downloadAndSaveFile($entity, $attachment, $file_group, $kobo_api);
                
                if ($video_file) {
                    // Cria MetaList com a URL do arquivo salvo
                    $metalist = new \MapasCulturais\Entities\MetaList();
                    $metalist->owner = $entity;
                    $metalist->group = 'videos';
                    $metalist->title = $attachment['media_file_basename'] ?? 'Vídeo';
                    $metalist->value = $video_file->url;
                    $metalist->save(true);
                    
                    $app->log->info(sprintf(i::__('KoboSyncJob: Vídeo salvo na galeria: %s'), $metalist->title));
                }
            } catch (\Exception $e) {
                $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao salvar vídeo: %s'), $e->getMessage()));
            }
        }
    }
    
    protected function parseLocation(string $location_string)
    {
        $app = App::i();
        
        $parts = preg_split('/\s+/', trim($location_string));
        
        $app->log->info(sprintf(i::__('KoboSyncJob: Parseando localização: %s'), $location_string));
        
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            $latitude = (float)$parts[0];
            $longitude = (float)$parts[1];
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Coordenadas extraídas - Latitude: %s, Longitude: %s'), $latitude, $longitude));
            
            return new \MapasCulturais\Types\GeoPoint($longitude, $latitude);
        }
        
        throw new \Exception(sprintf(i::__('Formato de localização inválido: %s'), $location_string));
    }
    
    protected function downloadAndSaveFile($entity, array $attachment, string $file_group, KoboApi $kobo_api)
    {
        $app = App::i();
        
        try {
            $download_url = $attachment['download_url'] ?? null;
            $filename = $attachment['media_file_basename'] ?? $attachment['filename'] ?? 'file';
            $mimetype = $attachment['mimetype'] ?? 'application/octet-stream';
            
            if (!$download_url) {
                $app->log->warning(i::__('KoboSyncJob: URL de download não encontrada para arquivo'));
                return false;
            }
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Tentando baixar arquivo de: %s'), $download_url));
            $app->log->info(sprintf(i::__('KoboSyncJob: Entidade ID: %s, Grupo: %s, Nome arquivo: %s'), $entity->id ?? 'NOVO', $file_group, $filename));
            
            try {
                $file_content = $kobo_api->downloadFile($download_url);
            } catch (\Exception $e) {
                $app->log->warning(sprintf(i::__('KoboSyncJob: Erro ao baixar arquivo (continuando sem o arquivo): %s'), $e->getMessage()));
                $app->log->warning(sprintf(i::__('KoboSyncJob: URL tentada: %s'), $download_url));
                
                return false;
            }
            
            if ($file_content === null || empty($file_content)) {
                $app->log->warning(i::__('KoboSyncJob: Arquivo baixado está vazio'));
                return false;
            }
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Arquivo baixado com sucesso, tamanho: %d bytes'), strlen($file_content)));
            
            // Salva em arquivo temporário
            $tmp_file = tempnam(sys_get_temp_dir(), 'kobo_');
            file_put_contents($tmp_file, $file_content);
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Arquivo temporário criado: %s'), $tmp_file));
            
            // Cria a entidade File
            $file_class = $entity->fileClassName;
            $app->log->info(sprintf(i::__('KoboSyncJob: Criando entidade File da classe: %s'), $file_class));
            
            $file = new $file_class([
                'name' => $filename,
                'type' => $mimetype,
                'tmp_name' => $tmp_file,
                'error' => 0,
                'size' => filesize($tmp_file),
            ]);
            
            $file->group = $file_group;
            $file->owner = $entity;
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Salvando arquivo - grupo: %s, owner ID: %s'), $file->group, $entity->id));
            
            $file->save(true);
            
            // Limpa o arquivo temporário
            @unlink($tmp_file);
            
            $app->log->info(sprintf(i::__('KoboSyncJob: Arquivo salvo com sucesso: %s (ID: %s)'), $filename, $file->id ?? 'N/A'));
            return $file;
            
        } catch (\Exception $e) {
            $app->log->error(sprintf(i::__('KoboSyncJob: Erro ao salvar arquivo: %s'), $e->getMessage()));
            $app->log->error(sprintf(i::__('KoboSyncJob: Stack trace: %s'), $e->getTraceAsString()));
            
            return false;
        }
    }
}

