// A scrolling area that says where its content is cut off: the frame carries a shadow
// under the edge in whose direction content continues (is-clipped-top / is-clipped-bottom),
// measured on scroll, on resize and after every render, since the content follows the
// state. The frame's place in its layout and its lines are the caller's (className), the
// scroller's padding and background too (scrollerClassName); the shadows are the shared
// scroll-frame base's.
lodash.set(window, 'Pblsh.Components.ScrollFrame', ({ className = '', scrollerClassName = '', children }) => {
    const { useState, useEffect, useRef, useCallback, createElement } = wp.element;

    const [clipped, setClipped] = useState({ top: false, bottom: false });
    const scrollerElement = useRef(null);
    const resizeObserver = useRef(null);
    const measure = () => {
        const scroller = scrollerElement.current;
        if (!scroller) return;
        const top = scroller.scrollTop > 0;
        const bottom = scroller.scrollTop + scroller.clientHeight < scroller.scrollHeight - 1;
        setClipped((state) => state.top === top && state.bottom === bottom ? state : { top, bottom });
    };
    const scrollerRef = useCallback((scroller) => {
        resizeObserver.current?.disconnect();
        resizeObserver.current = null;
        scrollerElement.current = scroller;
        if (!scroller) return;
        resizeObserver.current = new ResizeObserver(measure);
        resizeObserver.current.observe(scroller);
        measure();
    }, []);
    useEffect(measure);

    return createElement('div', {
        className: 'pblsh--scroll-frame' + (className ? ' ' + className : '') + (clipped.top ? ' is-clipped-top' : '') + (clipped.bottom ? ' is-clipped-bottom' : ''),
    },
        createElement('div', {
            ref: scrollerRef,
            className: 'pblsh--scroll-frame__scroller' + (scrollerClassName ? ' ' + scrollerClassName : ''),
            onScroll: measure,
        }, children),
    );
});
