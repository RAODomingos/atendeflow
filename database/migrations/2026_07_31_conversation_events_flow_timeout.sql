-- Eventos de fluxo/timeout/etiquetas usados pelo código mas ausentes do enum.
-- Sem isso, o MySQL (modo estrito) rejeita a inserção ou grava string vazia,
-- e o RealtimeController não consegue detectar mudanças de fluxo/tags no SSE.
ALTER TABLE conversation_events MODIFY COLUMN `event_type` enum(
    'created', 'assigned', 'transferred', 'status_changed',
    'priority_changed', 'note_added', 'flow_started',
    'flow_completed', 'tag_added', 'tag_removed',
    'department_changed', 'reopened', 'flow_stopped',
    'flow_timeout', 'flow_notification', 'snoozed', 'csat', 'merged'
) NOT NULL;
