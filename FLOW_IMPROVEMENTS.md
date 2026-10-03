# Melhorias Implementadas no Sistema de Fluxos

## Resumo das Melhorias

Todas as 10 melhorias identificadas foram implementadas no sistema de fluxos do OminiDesk.

## 1. Delay Assíncrono (Remoção do sleep())

**Problema:** O `sleep()` bloqueava o thread do servidor web, afetando performance.

**Solução:** 
- Implementado delay usando timestamp no banco de dados
- Adicionado campo `timeout_at` em `conversation_flow_states`
- Criado método `processPendingDelays()` para processar delays agendados
- Worker assíncrono processa delays sem bloquear o servidor

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`
- `database/migrations/2026_07_23_flow_improvements.sql`

## 2. Sistema de Templates Avançado

**Problema:** Sistema de substituição simples sem suporte a lógica condicional.

**Solução:**
- Adicionadas novas variáveis: `{empresa}`, `{documento}`, `{data}`, `{hora}`, `{data_hora}`
- Implementado suporte a condicionais: `{if:campo}texto{/if}`
- Implementado suporte a else: `{if:campo}texto{else}alternativo{/if}`
- Melhorada resolução de dados do contato

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`

## 3. Nó de Condição com Operadores Avançados

**Problema:** Apenas suportava igualdade exata de strings.

**Solução:**
- Implementados 16 operadores de comparação:
  - `equals`, `not_equals`
  - `contains`, `not_contains`
  - `starts_with`, `ends_with`
  - `greater_than`, `less_than`, `greater_equal`, `less_equal`
  - `regex`, `in`, `not_in`
  - `empty`, `not_empty`
- Adicionado método `evaluateCondition()` com lógica robusta
- Logs detalhados de avaliação de condições

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`

## 4. Versionamento de Fluxos

**Problema:** Atualização destrutiva quebrava conversas em andamento.

**Solução:**
- Adicionados campos: `version`, `parent_flow_id`, `is_draft` em `flows`
- Implementado `createNewVersion()` para criar versões incrementais
- Implementado `publishVersion()` para publicar versões específicas
- Implementado `getVersions()` para listar histórico de versões
- Conversas em andamento continuam usando a versão original

**Arquivos modificados:**
- `app/Models/Flow.php`
- `database/migrations/2026_07_23_flow_improvements.sql`

## 5. Timeout Configurável com Fallback

**Problema:** Fluxos ficavam presos indefinidamente sem resposta do cliente.

**Solução:**
- Adicionado campo `timeout_at` em `conversation_flow_states`
- Adicionado campo `last_activity_at` para rastrear atividade
- Implementado `handleTimeout()` com fallback para handoff
- Configuração global via tabela `flow_settings`
- Timeout padrão: 30 minutos (configurável)

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`
- `database/migrations/2026_07_23_flow_improvements.sql`

## 6. Sistema de Retry com Backoff Exponencial

**Problema:** Mensagens outbound não tinham retry em falhas temporárias.

**Solução:**
- Adicionados campos em `messages`: `retry_count`, `next_retry_at`, `delivery_status`
- Implementado `scheduleRetry()` com backoff exponencial
- Implementado `processPendingRetries()` para processar retries
- Configurações: máximo de retries (3) e backoff base (60s)
- Status de delivery: `pending`, `sent`, `delivered`, `failed`

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`
- `database/migrations/2026_07_23_flow_improvements.sql`

## 7. Limpeza de Estados Antigos

**Problema:** Acúmulo de estados de fluxo inativos no banco de dados.

**Solução:**
- Implementado `cleanupOldStates()` para limpeza periódica
- Limpa estados inativos antigos (padrão: 90 dias)
- Limpa logs de execução antigos
- Limpa respostas de fluxo antigas (180 dias)
- Configurável via `flow_settings`

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`

## 8. Validação de Configuração de Nós

**Problema:** Configurações inválidas causavam erros em runtime.

**Solução:**
- Implementado `validateNodes()` com validações por tipo
- Validações específicas para cada tipo de nó:
  - `start`: não deve ter opções
  - `menu/button_list/list_menu`: deve ter opções com labels
  - `condition`: deve ter variável, operador e 2 opções
  - `delay`: segundos entre 0 e 3600
  - `assign_department/user/tag`: IDs obrigatórios
  - `image/audio/video/send_file`: URL válida obrigatória
- Validação de nó start obrigatório
- Validação de chaves duplicadas

**Arquivos modificados:**
- `app/Controllers/FlowController.php`

## 9. Logs de Execução de Fluxo

**Problema:** Dificuldade em debugar execuções de fluxo.

**Solução:**
- Criada tabela `flow_execution_logs`
- Campos: `conversation_id`, `flow_id`, `node_id`, `event_type`, `event_data`, `created_at`
- Implementado `logExecution()` para registrar eventos
- Eventos registrados: `flow_started`, `delay_scheduled`, `delay_completed`, `condition_evaluated`, `timeout_triggered`, etc.
- Índices para consultas eficientes

**Arquivos modificados:**
- `app/Services/FlowEngineService.php`
- `database/migrations/2026_07_23_flow_improvements.sql`

## 10. Suporte a Múltiplos Fluxos com Priorização

**Problema:** Apenas um fluxo ativo por canal, sem critérios de seleção.

**Solução:**
- Adicionados campos: `priority`, `config` em `flows`
- Implementado `getActiveFlowsForChannel()` para listar fluxos ativos
- Implementado `getBestFlowForChannel()` com seleção baseada em contexto
- Critérios de seleção:
  - Prioridade (maior primeiro)
  - Departamento específico
  - Horário comercial
  - Tags requeridas
- Fallback para fluxo de maior prioridade

**Arquivos modificados:**
- `app/Models/Flow.php`
- `database/migrations/2026_07_23_add_flow_priority.sql`

## Worker Assíncrono

Criado worker em `workers/flow_worker.php` para processar tarefas assíncronas:
- Processa delays pendentes
- Processa retries de mensagens
- Executa limpeza de estados antigos (semanalmente)

**Execução via cron:**
```bash
# Executar a cada minuto
* * * * * php /path/to/atendeflow/workers/flow_worker.php
```

## Configurações Globais

Tabela `flow_settings` com configurações:
- `flow_timeout_minutes`: Timeout padrão (30)
- `flow_max_retries`: Máximo de retries (3)
- `flow_retry_backoff_seconds`: Backoff base (60)
- `flow_cleanup_days`: Dias para manter logs (90)

## Migrations

1. `2026_07_23_flow_improvements.sql` - Migração principal
2. `2026_07_23_add_flow_priority.sql` - Adiciona prioridade e config

## Próximos Passos

Para aplicar as melhorias:

1. Executar as migrations no banco de dados
2. Configurar o cron job para o worker
3. Ajustar configurações em `flow_settings` conforme necessário
4. Testar os novos tipos de nós e operadores de condição

## Benefícios

- **Performance:** Delays assíncronos não bloqueiam o servidor
- **Confiabilidade:** Sistema de retry garante entrega de mensagens
- **Manutenibilidade:** Versionamento permite atualizações sem quebra
- **Debugabilidade:** Logs detalhados facilitam troubleshooting
- **Flexibilidade:** Múltiplos fluxos com critérios de seleção
- **Robustez:** Validações previnem erros de configuração
- **Escalabilidade:** Limpeza automática mantém banco saudável
