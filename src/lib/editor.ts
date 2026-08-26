// SPDX-License-Identifier: AGPL-3.0-or-later

import { StarterKit } from '@tiptap/starter-kit'

export const editorExtensions = [
  StarterKit.configure({
    code: false,
    codeBlock: false,
    hardBreak: false,
    horizontalRule: false,
    link: false,
    strike: false,
    trailingNode: false,
    heading: {
      levels: [1, 2, 3, 4, 5, 6],
    },
  }),
]
