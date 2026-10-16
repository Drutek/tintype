# Storybook addon

Shows the current story's visual test status inside Storybook, and runs builds
without leaving it.

```bash
npm i -D @tintype/storybook-addon @tintype/cli
```

```ts
// .storybook/main.ts
export default {
  addons: [
    { name: '@tintype/storybook-addon', options: { server: 'https://tintype.example.com' } },
  ],
};
```

Provide the token through `TINTYPE_PROJECT_TOKEN` in the environment that runs
`storybook dev`. It stays in Storybook's Node process and is never sent to the
browser. Snapshot images come from short-lived signed URLs.

The **Visual tests** panel shows, for the current branch's latest build:
- each mode/browser snapshot's status;
- the new, baseline and diff images;
- accessibility issues;
- a link to review.

**Run visual tests** runs the CLI in your project, with output streamed into
the panel.
