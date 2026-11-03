import { fullMarkdown, txt } from '../lib/markdown';

export const GET = async () => txt(await fullMarkdown());
