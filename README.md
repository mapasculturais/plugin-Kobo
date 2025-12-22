# Plugin Kobo
> Plugin de integração e sincronização de dados entre Kobo Toolbox e Mapas Culturais

## Efeitos do uso do plugin

- O plugin permite sincronizar automaticamente dados de formulários do Kobo Toolbox com entidades do Mapas Culturais, criando ou atualizando registros periodicamente. Isso facilita a importação de dados coletados através de formulários do Kobo diretamente para a plataforma Mapas Culturais.

## Requisitos Mínimos
- Mapas Culturais v7.6.0^
- Token de API do Kobo Toolbox **com permissões de administrador** (necessário para acessar o endpoint `/api/v2/users`)
- Acesso à API v2 do Kobo Toolbox

## Funcionamento

### Sincronização Automática
- O plugin executa **jobs de sincronização periódicos** que buscam dados de formulários do Kobo Toolbox e os importam para entidades do Mapas Culturais.
- Cada integração configurada pode ter sua própria periodicidade (ex: diária, semanal, etc.).
- Os dados são mapeados automaticamente entre os campos do formulário Kobo e os campos das entidades do Mapas Culturais.

### Processamento de Submissões
- O sistema identifica automaticamente o usuário que preencheu o formulário no Kobo através do email.
- As entidades são criadas ou atualizadas com base no UUID único da submissão do Kobo (`kobo_submission_uuid`).
- Se uma entidade já existe com o mesmo `kobo_submission_uuid`, ela é atualizada; caso contrário, uma nova entidade é criada.

### Metadados
- O plugin registra automaticamente os seguintes metadados nas entidades configuradas:
  - `kobo_submission_uuid`: UUID único da submissão do Kobo, usado para identificar e atualizar entidades existentes.
  - `kobo_last_modified`: Data da última modificação do submission, usado para otimizar o processamento e evitar reprocessar dados já sincronizados.

### Otimização de Processamento
- O plugin utiliza o campo `end` do submission para determinar se houve modificações desde a última sincronização.
- Apenas submissions modificados desde a última sincronização são processados, reduzindo significativamente o tempo de execução e a carga no servidor.
- Na primeira execução, todos os registros são processados. Nas execuções seguintes, apenas os modificados são sincronizados.

## Configuração básica

### Para ativar o plugin no ambiente de desenvolvimento ou produção

- No arquivo `docker/common/config.d/plugins.php`, adicione `'Kobo'`:

```php
<?php

return [
    'plugins' => [
        'MultipleLocalAuth' => [ 'namespace' => 'MultipleLocalAuth' ],
        'SamplePlugin' => ['namespace' => 'SamplePlugin'],
        'Kobo' => [
            'namespace' => 'Kobo',
        ],
    ]
];
```

### Configurações adicionais

- O plugin permite a personalização dos parâmetros de integração por meio da chave `config`, conforme exemplo abaixo:

```php
    <?php

    return [
        'plugins' => [
            'Kobo' => [
                'namespace' => 'Kobo',
                'config' => [
                    'api_url' => env('KOBO_API_URL', 'https://kf.kobotoolbox.org/api/v2'),
                    'api_token' => env('KOBO_API_TOKEN', ''),
                    
                    'integrations' => [
                        'nome_da_integracao' => [
                            'enabled' => true,
                            'kobo_form_id' => 'ChaveAqui',
                            'target_entity' => 'MapasCulturais\Entities\Agent',
                            'periodicity' => '+1 day',
                            'field_mapping' => [
                                'Nome' => 'name',
                                'Descricao' => 'shortDescription',
                                'Descricao_Longa' => 'longDescription',
                                
                                // Arquivos
                                'avatar' => 'avatar',
                                'galeria_imagens' => 'gallery',
                                'videos' => 'videos',
                                
                                // Taxonomias
                                'areas_atuacao' => 'taxonomy:area',
                                'tags' => 'taxonomy:tag',
                                
                            ],
                        ],
                    ],
                ]
            ]
        ]
    ];
```

- **Descrição dos parâmetros:**
  - `api_url`: URL base da API do Kobo Toolbox (padrão: `https://kf.kobotoolbox.org/api/v2`).
  - `api_token`: Token de autenticação da API do Kobo Toolbox (obrigatório). **IMPORTANTE:** O token deve ter permissões de administrador, pois o plugin utiliza o endpoint `/api/v2/users` para identificar usuários através do email, e esse endpoint requer privilégios administrativos.
  - `integrations`: Array de configurações de integração, onde cada chave representa uma integração única:
    - `enabled`: Define se a integração está ativa (padrão: `false`).
    - `kobo_form_id`: ID único do formulário no Kobo Toolbox (Asset UID).
    - `target_entity`: Nome da entidade do Mapas Culturais que receberá os dados. Pode ser uma entidade padrão (ex: `MapasCulturais\Entities\Agent`, `MapasCulturais\Entities\Space`, `MapasCulturais\Entities\Event`, `MapasCulturais\Entities\Project`) ou uma entidade customizada (ex: `CustomEntity\Entities\Especie`). Use o nome completo da classe incluindo namespace.
    - `periodicity`: Intervalo de execução do job de sincronização (ex: `+1 day`, `+1 week`, `+1 hour`).
    - `field_mapping`: Mapeamento entre campos do formulário Kobo (chave) e campos da entidade Mapas Culturais (valor).

- **IMPORTANTE:** Os valores podem ser definidos diretamente ou usando variáveis de ambiente via `env()`.

## Mapeamento de Campos

- O `field_mapping` permite mapear qualquer campo do formulário Kobo para qualquer campo da entidade do Mapas Culturais.
- Campos não mapeados são ignorados durante a sincronização.
- O campo `_submitted_by` do Kobo é usado automaticamente para identificar o usuário no Mapas Culturais através do email.

### Tipos de Mapeamento

#### Campos Simples
Mapeamento direto de valores simples (string, número, etc.):

```php
'field_mapping' => [
    'Nome' => 'name',
    'Descricao' => 'shortDescription',
    'Email' => 'emailPublico',
]
```

#### Arquivos (Avatar, Galeria de Imagens e Vídeos)
Para mapear arquivos dos attachments do Kobo:

```php
'field_mapping' => [
    'avatar' => 'avatar',                    // Arquivo único (avatar)
    'grupo_imagens' => 'imageGallery',       // Array de imagens (galeria)
    'grupo_videos/videos' => 'videoGallery', // Vídeo único ou array (vídeos)
]
```

**Nota:** Os arquivos são baixados automaticamente dos attachments do Kobo e salvos na entidade. O plugin procura os attachments pelo `question_xpath` correspondente ao campo mapeado.

#### Taxonomias
Para mapear campos do Kobo para taxonomias da entidade, use o prefixo `taxonomy:` seguido do slug da taxonomia:

```php
'field_mapping' => [
    'areas_atuacao' => 'taxonomy:area',      // Mapeia para a taxonomia 'area' (Agentes e Espaços)
    'tags' => 'taxonomy:tag',                // Mapeia para a taxonomia 'tag'
]
```

**Funcionamento:**
- O valor do campo Kobo é separado por espaços em branco para criar múltiplos termos.
- Exemplo: `"Cosmético Econômico"` → `['Cosmético', 'Econômico']`
- Os termos são normalizados automaticamente (substituição de underscores, capitalização, etc.).
- Os termos são salvos na taxonomia especificada após o salvamento da entidade.

## Exemplo Completo de Configuração

```php
'Kobo' => [
    'namespace' => 'Kobo',
    'config' => [
        'api_url' => env('KOBO_API_URL', 'https://kf.kobotoolbox.org/api/v2'),
        'api_token' => env('KOBO_API_TOKEN', ''),
        
        'integrations' => [
            'sincronizacao_agentes' => [
                'enabled' => true,
                'kobo_form_id' => 'ChaveAqui',
                'target_entity' => 'MapasCulturais\Entities\Agent',
                'periodicity' => '+1 day',
                'field_mapping' => [
                    // Campos simples
                    'Nome' => 'name',
                    'Descricao' => 'shortDescription',
                    'Descricao_Longa' => 'longDescription',
                    'Email' => 'emailPublico',
                    'Telefone' => 'telefonePublico',
                    
                    // Arquivos (baixados automaticamente dos attachments)
                    'avatar' => 'avatar',
                    'galeria_imagens' => 'gallery',
                    'videos' => 'videos',
                    
                    // Taxonomias (formato: taxonomy:slug)
                    'areas_atuacao' => 'taxonomy:area',
                    'tags' => 'taxonomy:tag',
                    
                    // Tipos
                    'tipo_agente' => 'type',
                ],
            ],
        ],
    ],
],
```

## Observações

- O plugin cria jobs recorrentes que são executados automaticamente pelo sistema de jobs do Mapas Culturais.
- As entidades criadas ou atualizadas são associadas ao usuário que preencheu o formulário no Kobo (se encontrado no Mapas Culturais).
- O metadado `kobo_submission_uuid` é usado para evitar duplicação de entidades, garantindo que cada submissão do Kobo corresponda a uma única entidade no Mapas Culturais.
- O plugin otimiza o processamento verificando o campo `end` do submission e comparando com o `kobo_last_modified` mais recente das entidades já sincronizadas, processando apenas submissions modificados.
- A sincronização ocorre automaticamente conforme a periodicidade configurada, sem necessidade de intervenção manual.
- Em caso de erro durante o processamento de uma submissão, o sistema registra o erro nos logs e continua processando as demais submissões.

