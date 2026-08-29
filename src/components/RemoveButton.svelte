<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { onDestroy } from 'svelte'
  import { removeButtonStrings } from '../lib/strings'

  let {
    label,
    description,
    confirmDescription,
    disabled = false,
    onRemove,
  }: {
    /** What is being removed, used to build the accessible name of both steps. */
    label: string
    description: string
    /** What the second press will do, said before it happens. */
    confirmDescription: string
    disabled?: boolean
    onRemove: () => void
  } = $props()

  /** How long the button stays armed: long enough to confirm, short enough to forget. */
  const ARMED_MS = 4000

  let armed = $state(false)
  let timer: ReturnType<typeof setTimeout> | undefined

  onDestroy(() => clearTimeout(timer))

  /**
   * Two presses instead of a dialog: the first arms and says what will happen, the second does it.
   * A dialog would interrupt the reading; an armed button waits in place and disarms by itself.
   */
  function press(event: MouseEvent): void {
    event.stopPropagation()
    if (!armed) {
      armed = true
      clearTimeout(timer)
      timer = setTimeout(() => (armed = false), ARMED_MS)
      return
    }
    clearTimeout(timer)
    armed = false
    onRemove()
  }
</script>

<button
  type="button"
  class="remove-button"
  class:armed
  {disabled}
  aria-label={armed ? removeButtonStrings.confirmLabel(label) : removeButtonStrings.label(label)}
  title={armed ? confirmDescription : description}
  onclick={press}
>{armed ? removeButtonStrings.armedGlyph : removeButtonStrings.glyph}</button>
