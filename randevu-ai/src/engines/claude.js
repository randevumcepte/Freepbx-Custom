'use strict';
const Anthropic = require('@anthropic-ai/sdk');
const config = require('./../config');
const { makeChunker } = require('./../chunker');

// UCRETLI / API beyin: Claude (Anthropic). Streaming + cumle-cumle TTS.
class ClaudeEngine {
  constructor({ system, ctx, tools, executeTool }) {
    this.client = new Anthropic();
    this.ctx = ctx;
    this.system = system;
    this.tools = tools;               // Anthropic formatinda tool tanimlari
    this.executeTool = executeTool;
    this.messages = [];
  }

  async run(userContent, onSentence) {
    this.messages.push({ role: 'user', content: userContent });
    const chunker = onSentence ? makeChunker(onSentence) : null;

    for (let guard = 0; guard < 8; guard++) {
      const stream = this.client.messages.stream({
        model: config.claude.model,
        max_tokens: 1024,
        system: this.system,
        tools: this.tools,
        output_config: { effort: 'low' },
        messages: this.messages,
      });

      let spoken = '';
      stream.on('text', (d) => { spoken += d; if (chunker) chunker.push(d); });

      const resp = await stream.finalMessage();
      this.messages.push({ role: 'assistant', content: resp.content });
      if (chunker) chunker.flush();

      if (resp.stop_reason === 'tool_use') {
        const toolResults = [];
        for (const block of resp.content) {
          if (block.type !== 'tool_use') continue;
          const out = await this.executeTool(block.name, block.input, this.ctx);
          toolResults.push({
            type: 'tool_result', tool_use_id: block.id, content: out.toModel,
            ...(out.isError ? { is_error: true } : {}),
          });
        }
        this.messages.push({ role: 'user', content: toolResults });
        continue;
      }
      return { text: spoken.trim(), control: this.ctx.control };
    }

    if (chunker) chunker.flush();
    return { text: 'Sizi operatore aktariyorum.', control: 'transfer' };
  }
}

module.exports = { ClaudeEngine };
