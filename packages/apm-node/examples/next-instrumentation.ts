// instrumentation.ts (project root, next to next.config.*)
// Next.js calls register() once per server instance; only the Node.js runtime is instrumented.
export async function register() {
  const { registerFalak } = await import('@othmanhaba/apm-node/next');
  await registerFalak();
}
