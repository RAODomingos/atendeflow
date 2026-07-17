<?php

namespace App\Services;

class AiSuggestionService
{
    private $conversationHistory;
    private $cannedResponses;
    private $flowService;
    private $config;

    public function __construct()
    {
        $this->conversationHistory = [];
        $this->cannedResponses = $this->loadCannedResponses();
        $this->flowService = null;
        $this->config = [
            'min_confidence' => 0.6,
            'max_suggestions' => 5,
            'enable_flow_suggestions' => true,
            'avoid_duplicates' => true,
            'language' => 'pt',
            'auto_translate' => false,
        ];
    }

    public function getSuggestions(string $message, int $conversationId): array
    {
        $this->loadConversationHistory($conversationId);

        $suggestions = [];
        $message_analysis = $this->analyzeMessage($message);
        $intent = $this->detectIntent($message, $message_analysis);
        $confidence = $this->calculateConfidence($message, $intent);

        if ($confidence < $this->config['min_confidence']) {
            return $this->getFallbackSuggestions();
        }

        $suggestions = array_merge(
            $this->getRelevantCannedResponses($message, $intent),
            $this->getRelatedFlowSuggestions($intent, $conversationId),
            $this->getContextualSteps($intent, $message)
        );

        $suggestions = $this->filterAndRankSuggestions($suggestions, $message_analysis);
        $suggestions = array_slice($suggestions, 0, $this->config['max_suggestions']);

        return [
            'suggestions' => $suggestions,
            'intent' => $intent,
            'confidence' => $confidence,
            'alternatives' => $this->getAlternativePaths($intent),
        ];
    }

    private function analyzeMessage(string $message): array
    {
        $words = preg_split('/\s+/', strtolower(trim($message)), -1, PREG_SPLIT_NO_EMPTY);

        $patterns = [
            'problema' => ['problema', 'erro', 'issue', 'bug', 'não funciona', 'falha'],
            'elogiamento' => ['bom', 'ótimo', 'excelente', 'gostei', 'perfeito', 'agradável'],
            'duvida' => ['como', 'quando', 'onde', 'porque', 'what', 'how', 'why'],
            'urgente' => ['rápido', 'urgente', 'prioridade', 'agora', 'immediately'],
            'informação' => ['info', 'sobre', 'preciso saber', 'details'],
            'demonstração' => ['demo', 'testar', 'experimentar', ' trial'],
            'contato' => ['contato', 'telefone', 'email', 'whatsapp', 'número'],
            'reclamação' => ['reclamar', 'queixa', 'problema seria'],
            'apoio' => ['ajuda', 'suporte', 'help', 'suporte'],
            'pagamento' => ['pagamento', 'cobrança', 'preço', 'tarifa', 'invoice'],
        ];

        $analysis = [
            'words' => $words,
            'keywords' => $this->extractKeywords($words),
            'patterns' => $this->detectPatterns($words, $patterns),
            'entities' => $this->extractEntities($message),
            'sentiment' => $this->analyzeSentiment($message),
            'urgency' => $this->calculateUrgency($message, $words),
            'categories' => $this->categorizeMessage($words, $patterns),
        ];

        return $analysis;
    }

    private function extractKeywords(array $words): array
    {
        $stopwords = [
            'de', 'da', 'do', 'das', 'dos', 'para', 'com', 'sem', 'por', 'sobre',
            'na', 'no', 'nas', 'nos', 'uma', 'um', 'as', 'os', 'em', 'no', 'na'
        ];

        $keywords = array_filter($words, function($word) use ($stopwords) {
            return !in_array($word, $stopwords) && strlen($word) > 2;
        });

        return $keywords;
    }

    private function detectPatterns(array $words, array $patternList): array
    {
        $detectedPatterns = [];

        foreach ($patternList as $pattern => $keywords) {
            if (array_intersect($words, $keywords)) {
                $detectedPatterns[] = $pattern;
            }
        }

        return $detectedPatterns;
    }

    private function extractEntities(string $message): array
    {
        $entities = [];

        if (preg_match_all('/\b(\d{2,5}[\-|\.]\d{2,5}[\-|\.]\d{4,})/', $message, $matches)) {
            $entities['dates'] = $matches[1];
        }

        if (preg_match_all('/\b(\+\d{1,3}[\s\-]?\d{2,4}[\s\-]?\d{7,10})/', $message, $matches)) {
            $entities['phones'] = $matches[1];
        }

        if (preg_match_all('/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $message, $matches)) {
            $entities['emails'] = $matches[1];
        }

        if (preg_match_all('/\b(http[s]?:\/\/(?:[\w\-]+\.)+[\w\-]+\/[\w\-\._\/~:]+\??[\w\-\._\?&=%]*)?/', $message, $matches)) {
            $entities['urls'] = $matches[1];
        }

        return $entities;
    }

    private function analyzeSentiment(string $message): array
    {
        $positive_words = ['bom', 'ótimo', 'excelente', 'gostei', 'agradável', 'perfeito', 'feliz', 'contents'];
        $negative_words = ['ruim', 'péssimo', 'terrível', 'odioso', 'problema', 'bloqueio', 'demora', 'insatisfeito'];

        $words = preg_split('/\s+/', strtolower($message), -1, PREG_SPLIT_NO_EMPTY);

        $positive_count = 0;
        $negative_count = 0;

        foreach ($words as $word) {
            if (in_array($word, $positive_words)) $positive_count++;
            if (in_array($word, $negative_words)) $negative_count++;
        }

        $total = $positive_count + $negative_count;
        $sentiment_score = ($total > 0) ? ($positive_count - $negative_count) / $total : 0;

        return [
            'score' => $sentiment_score,
            'type' => $sentiment_score > 0.1 ? 'positive' : ($sentiment_score < -0.1 ? 'negative' : 'neutral'),
            'magnitude' => min($positive_count, $negative_count) / max($positive_count, $negative_count, 1),
        ];
    }

    private function calculateUrgency(string $message, array $words): string
    {
        $urgency_keywords = ['rápido', 'urgente', 'prioridade', 'agora', 'urgência', 'immediate', 'asap', 'tempo'];

        if (array_intersect($words, $urgency_keywords)) {
            return 'high';
        }

        $requires_expectativas = ['como', 'when', 'what', 'where', 'porque', 'why', 'how', 'will'];
        if (array_intersect($words, $requires_expectativas)) {
            return 'medium';
        }

        return 'low';
    }

    private function categorizeMessage(array $words, array $patterns): array
    {
        $categories = [];

        foreach ($patterns as $pattern => $keywords) {
            if (array_intersect($words, $keywords)) {
                $categories[] = $pattern;
            }
        }

        return $categories;
    }

    private function loadCannedResponses(): array
    {
        return [
            'problema' => [
                ['response' => 'Entendo seu problema. Está me ajudando a te ajudar melhor. Pode me contar quais são os passos que causaram esse problema?', 'category' => 'investigation'],
                ['response' => 'Vou investigar isso imediatamente. Pode me dizer quanto tempo faz que isso está acontecendo?', 'category' => 'investigation'],
                ['response' => 'Vamos verificar isso com urgência. Posso resolver isso agora?', 'category' => 'action'],
            ],
            'elogiamento' => [
                ['response' => 'Que bom ouvir isso! Estamos muito felizes com seu feedback. Como posso retribuir o favor?', 'category' => 'engaging'],
                ['response' => 'Obrigado pelo ótimo feedback! Como podemos melhorar ainda mais?', 'category' => 'reciprocity'],
                ['response' => 'Estamos orgulhosos do serviço que oferecemos. Há algo que possamos fazer por você hoje?', 'category' => 'reciprocity'],
            ],
            'duvida' => [
                ['response' => 'Claro! Aqui estão os passos: Primeiros, conta comigo para ou... e depois... e então... Você precisa instalar apenas...', 'category' => 'instruction'],
                ['response' => 'Sim, posso ajudar! Deixe-me te mostrar um tutorial rápido. Você pode acompanhar aqui: link_demostratacao', 'category' => 'tutorial'],
                ['response' => 'Nossos docs cobrem isso no capítulo 4. Quer que eu encontre a seção específica?', 'category' => 'resource'],
            ],
            'urgente' => [
                ['response' => 'Entendo o quão urgente isso é. Vou priorizar seu atendimento e assim que possível obter uma solução.', 'category' => 'urgency'],
                ['response' => 'Vamos escalar isso agora mesmo! Você será atendido por um humano em instantes.', 'category' => 'escalation'],
                ['response' => 'Para sua urgência, estou te conectando diretamente com a agente sênior. Onde parou?', 'category' => 'escalation'],
            ],
            'demonstração' => [
                ['response' => 'Perfeito! Vou agendar uma demonstração por você. Qual horário você prefere?', 'category' => 'scheduling'],
                ['response' => 'Excelente! Vamos preparar uma demonstração personalizada. Precise de mais alguns detalhes.', 'category' => 'scheduling'],
                ['response' => 'Vamos te mostrar como! Vou agendar você com um de nossos especialistas. Qual dia funciona para você?', 'category' => 'scheduling'],
            ],
            'contato' => [
                ['response' => 'Estou aqui para ajudar! Qual número para entrar em contato?', 'category' => 'followup'],
                ['response' => 'Vamos entrar em contato com você o mais rápido possível. Qual a melhor maneira de contatá-lo?', 'category' => 'followup'],
                ['response' => 'Vou passar para um agente humano que pode lhe ajudar por telefone. Qual é o seu número?', 'category' => 'escalation'],
            ],
            'default' => [
                ['response' => 'Obrigado por me contar! Como posso ajudar além disso?', 'category' => 'followup'],
                ['response' => 'Interessante! Posso ajudar com o que mais?', 'category' => 'clarification'],
                ['response' => 'Entendo seu pedido. Como você precisa disso feito?', 'category' => 'refinement'],
            ],
        ];
    }

    private function detectIntent(string $message, array $analysis): array
    {
        $patterns = $analysis['patterns'];
        $sentiment = $analysis['sentiment'];
        $urgency = $analysis['urgency'];
        $categories = $analysis['categories'];

        $intent_scores = [
            'investigation' => 0,
            'action' => 0,
            'escalation' => 0,
            'scheduling' => 0,
            'resource' => 0,
            'clarification' => 0,
            'followup' => 0,
            'reciprocity' => 0,
            'engagement' => 0,
            'tutorial' => 0,
            'instruction' => 0,
        ];

        foreach ($patterns as $pattern) {
            if (isset($this->cannedResponses[$pattern])) {
                foreach ($this->cannedResponses[$pattern] as $response) {
                    $intent_scores[$response['category']] += 3;
                }
            }
        }

        if ($sentiment['type'] === 'negative') $intent_scores['escalation'] += 4;
        if ($sentiment['type'] === 'positive') $intent_scores['engagement'] += 2;

        if ($urgency === 'high') $intent_scores['escalation'] += 3;
        if ($urgency === 'medium') $intent_scores['action'] += 2;

        if (in_array('demonstração', $categories)) $intent_scores['scheduling'] += 4;
        if (in_array('duvida', $categories)) $intent_scores['instruction'] += 3;

        if ($sentiment['magnitude'] > 0.7) $intent_scores['escalation'] += 2;

        arsort($intent_scores);
        $primary_intent = key($intent_scores);

        return [
            'primary' => $primary_intent,
            'scores' => $intent_scores,
            'confidence' => $this->calculateIntentConfidence($intent_scores),
        ];
    }

    private function calculateIntentConfidence(array $intentScores): float
    {
        $primaryScore = current($intentScores);
        $totalScore = array_sum($intentScores);
        return $totalScore > 0 ? $primaryScore / $totalScore : 0;
    }

    private function getRelevantCannedResponses(string $message, array $intentAnalysis): array
    {
        $intent = $intentAnalysis['primary'];
        $suggestions = [];

        if (isset($this->cannedResponses[$intent])) {
            $responses = $this->cannedResponses[$intent];

            usort($responses, function($a, $b) {
                return rand(-1, 1);
            });

            $suggestions = array_slice($responses, 0, 2);
        }

        return array_map(function($item) {
            return [
                'type' => 'canned_response',
                'text' => $item['response'],
                'category' => $item['category'],
                'confidence' => 0.7 + (rand() / rand(1, 3)),
                'requires_input' => in_array($item['category'], ['instruction', 'scheduling']),
            ];
        }, $suggestions);
    }

    private function getRelatedFlowSuggestions(string $intent, int $conversationId): array
    {
        if (!$this->config['enable_flow_suggestions']) {
            return [];
        }

        return [
            [
                'type' => 'flow_suggestion',
                'text' => 'Posso iniciar um fluxo de conversa estruturado para este assunto. Quer que eu comece agora?',
                'category' => 'flow',
                'confidence' => 0.8,
                'flow_id' => $this->getFlowIdForIntent($intent),
                'estimated_time' => '5-10 minutos',
            ]
        ];
    }

    private function getContextualSteps(array $intent, string $originalMessage): array
    {
        $contextualSuggestions = [
            'investigation' => [
                'text' => 'Vou coletar mais informações para melhor entender sua situação.',
                'category' => 'process',
                'action' => 'collect_info',
            ],
            'action' => [
                'text' => 'Aqui está o que posso fazer para resolver seu problema imediatamente.',
                'category' => 'resolution',
                'action' => 'resolve_now',
            ],
            'escalation' => [
                'text' => 'Estou te conectando com um agente humano especializado para melhor atendê-lo.',
                'category' => 'human_transfer',
                'action' => 'transfer_human',
            ],
            'scheduling' => [
                'text' => 'Vou reservar um horário para uma demonstração dedicada e personalizada.',
                'category' => 'planning',
                'action' => 'schedule_demo',
            ],
        ];

        $intentKey = $intent['primary'] ?? 'default';

        if (isset($contextualSuggestions[$intentKey])) {
            return [$contextualSuggestions[$intentKey]];
        }

        return [];
    }

    private function filterAndRankSuggestions(array $suggestions, array $analysis): array
    {
        $filtered = array_filter($suggestions, function($suggestion) use ($analysis) {
            $should_filter = false;

            if ($suggestion['type'] === 'contextual' && $suggestion['action'] === 'transfer_human') {
                if ($analysis['urgency'] !== 'high' && $analysis['sentiment']['type'] !== 'negative') {
                    $should_filter = true;
                }
            }

            if ($suggestion['requires_input'] && strlen($suggestion['text']) < 20) {
                $should_filter = true;
            }

            if ($this->config['avoid_duplicates'] &&
                strpos($suggestion['text'], 'expiration') !== false) {
                $should_filter = true;
            }

            return !$should_filter;
        });

        usort($filtered, function($a, $b) {
            $score_a = $a['confidence'] * 100;
            $score_b = $b['confidence'] * 100;
            if ($score_b != $score_a) return $score_b <=> $score_a;
            if ($a['type'] === 'contextual' && $b['type'] !== 'contextual') return -1;
            return 0;
        });

        return $filtered;
    }

    private function getAlternativePaths(array $intentAnalysis): array
    {
        $intent = $intentAnalysis['primary'];
        $alternatives = [];

        $alternative_paths = [
            'investigation' => [
                'path' => 'collect_detailed_info',
                'description' => 'Coletar informações mais detalhadas antes de agir',
            ],
            'action' => [
                'path' => 'immediate_resolution',
                'description' => 'Resolver problema imediatamente com recursos disponíveis',
            ],
            'escalation' => [
                'path' => 'human_specialist',
                'description' => 'Transferir para especialista humano imediato',
            ],
            'scheduling' => [
                'path' => 'personal_demo',
                'description' => 'Agendar demonstração 1:1 personalizada',
            ],
        ];

        if (isset($alternative_paths[$intent])) {
            $alternatives[] = $alternative_paths[$intent];
        }

        $alternatives[] = ['path' => 'general_assistance', 'description' => 'Conversar com agente para orientação geral'];

        return $alternatives;
    }

    private function getFallbackSuggestions(): array
    {
        return [
            [
                'type' => 'clarification',
                'text' => 'Posso ajudar, mas preciso de mais informações. Pode me contar exatamente o que você precisa?',
                'category' => 'clarification',
                'confidence' => 0.5,
            ],
            [
                'type' => 'resource',
                'text' => 'Estou aqui para ajudar! Qual é o seu objetivo principal conosco?',
                'category' => 'guidance',
                'confidence' => 0.4,
            ],
            [
                'type' => 'followup',
                'text' => 'Obrigado por me contar! Como posso ajudar além disso?',
                'category' => 'followup',
                'confidence' => 0.3,
            ],
        ];
    }

    private function loadConversationHistory(int $conversationId): void
    {
        $this->conversationHistory = [
            ['type' => 'topic', 'value' => 'problema_cain'],
            ['type' => 'satisfaction', 'value' => 0.8],
            ['type' => 'duration', 'value' => 1500],
            ['type' => 'escalation_count', 'value' => 0],
            ['type' => 'resolution_time', 'value' => 1200],
        ];
    }

    private function getFlowIdForIntent(string $intent): int
    {
        $flow_map = [
            'problema' => 1,
            'elogiamento' => 2,
            'duvida' => 3,
            'urgente' => 4,
            'demonstração' => 5,
            'contato' => 6,
        ];

        return $flow_map[$intent] ?? 0;
    }

    public function __invoke(string $message, int $conversationId = 0): array
    {
        return $this->getSuggestions($message, $conversationId);
    }
}
