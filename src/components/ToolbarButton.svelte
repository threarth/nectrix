<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Snippet } from 'svelte'
  import type { CommandStrings } from '../lib/strings'

  let {
    command,
    toggle = false,
    active = false,
    disabled = false,
    onclick,
    children,
  }: {
    /** Label, accessible name and explanation of the command. */
    command: CommandStrings
    /** True for commands that switch a state on and off, false for one shot actions. */
    toggle?: boolean
    active?: boolean
    disabled?: boolean
    onclick: () => void
    /** Custom rendering of the label, for example the bold or italic sample letter. */
    children?: Snippet
  } = $props()
</script>

<button
  type="button"
  class:active
  {disabled}
  {onclick}
  title={command.description}
  aria-label={command.ariaLabel}
  aria-pressed={toggle ? active : undefined}
>{#if children}{@render children()}{:else}{command.label}{/if}</button>
