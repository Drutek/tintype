import type { SVGAttributes } from 'react';

/** Tintype mark: a photographic plate with an offset exposure. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                d="M6 4h20a2 2 0 0 1 2 2v20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm1 3v18h18V7H7Zm4 4h7v7h-7v-7Zm3 3h7v7h-7v-7Z"
            />
        </svg>
    );
}
