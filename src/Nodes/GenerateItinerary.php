<?php

namespace App\Nodes;

use App\Agents\ResearchAgent;
use App\Events\CreateItinerary;
use App\Events\ProgressEvent;
use App\Prompts;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class GenerateItinerary extends Node
{
    public function __construct(protected ChatHistoryInterface $history)
    {
    }

    /**
     * @throws \Throwable
     */
    public function __invoke(CreateItinerary $event, WorkflowState $state): \Generator|StopEvent
    {
        yield new ProgressEvent("\n\n============ Creating the itinerary ============\n\n");

        $message = \str_replace('{query}', $state->get('query'), Prompts::ITINERARY_WRITER);
        $message = \str_replace('{flights}', $state->get('flights'), $message);
        $message = \str_replace('{hotels}', $state->get('hotels'), $message);
        $message = \str_replace('{places}', $state->get('places'), $message);

        $result = ResearchAgent::make()
            ->withChatHistory($this->history)
            ->stream(
                new UserMessage($message)
            );

        foreach ($result as $item) {
            if ($item instanceof ToolCallResultMessage) {
                continue;
            }

            if ($item instanceof ToolCallMessage){
                yield new ProgressEvent(
                    \array_reduce($item->getTools(), function (string $carry, ToolInterface $tool): string {
                        $carry .= "\n- Calling tool: ".$tool->getName();
                        return $carry;
                    }, '')."\n"
                );
            }
            yield new ProgressEvent($item);
        }

        // Finally, the agent stream returns the AssistantMessage with the whole content
        $state->set('travel_plan', $result->getReturn()->getContent());

        return new StopEvent();
    }
}