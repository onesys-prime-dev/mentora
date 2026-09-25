import * as pdfjsLib from "pdfjs-dist";
import pdfjsWorker from "pdfjs-dist/build/pdf.worker.min.mjs?url";

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfjsWorker;

class PdfViewer {

    constructor(container) {

        this.container = container;

        this.pdf = null;

        this.currentPage = 1;

        this.totalPages = 0;

        this.zoom = 1;

        this.minZoom = 0.5;

        this.maxZoom = 3;

        this.zoomStep = 0.1;

        this.fitWidthScale = 1;

        this.viewer = null;

        this.toolbar = null;

        this.pageCounter = null;

        this.zoomLabel = null;

        this.pageContainer = null;

        this.pageWrappers = [];

        this.resizeTimeout = null;

        this.intersectionObserver = null;

    }

    async initialize() {

        const pdfUrl = this.container.dataset.url;

        if (!pdfUrl) {
            return;
        }

        this.pdf = await pdfjsLib
            .getDocument({
                url: pdfUrl
            })
            .promise;

        this.totalPages = this.pdf.numPages;

        this.container.innerHTML = "";

        this.buildLayout();

        await this.calculateFitWidth();

        this.zoom = this.fitWidthScale;

        this.updateZoomLabel();

        this.updatePageCounter();

        await this.buildPages();

        this.observePages();

        this.registerEvents();

    }

    buildLayout() {

        this.toolbar = document.createElement("div");

        this.toolbar.className =
            "sticky top-0 z-20 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4";

        const left = document.createElement("div");

        left.className =
            "flex items-center gap-3";

        const title = document.createElement("h2");

        title.className =
            "text-lg font-semibold";

        title.textContent =
            "PDF Viewer";

        this.pageCounter =
            document.createElement("span");

        this.pageCounter.className =
            "text-sm text-gray-500";

        left.append(
            title,
            this.pageCounter
        );

        const center =
            document.createElement("div");

        center.className =
            "flex items-center gap-2";

        const zoomOut =
            this.button("-", () => this.zoomOut());

        const fitWidth =
            this.button("Fit Width", () => this.fitWidth());

        const zoomIn =
            this.button("+", () => this.zoomIn());

        this.zoomLabel =
            document.createElement("span");

        this.zoomLabel.className =
            "w-20 text-center text-sm font-medium";

        center.append(
            zoomOut,
            this.zoomLabel,
            zoomIn,
            fitWidth
        );

        const right =
            document.createElement("div");

        const download =
            document.createElement("a");

        download.href =
            this.container.dataset.downloadUrl ||
            this.container.dataset.url;

        download.target =
            "_blank";

        download.rel =
            "noopener noreferrer";

        download.className =
            "rounded-lg bg-[#0040A6] px-4 py-2 text-sm font-medium text-white hover:bg-[#00338A]";

        download.textContent =
            "Download";

        const allowDownload =
            this.container.dataset.allowDownload === "true";

        if (allowDownload) {

            right.append(download);

        }

        this.toolbar.append(
            left,
            center,
            right
        );

        this.viewer =
            document.createElement("div");

        this.viewer.className =
            "h-[70vh] overflow-y-auto bg-slate-200 p-8";

        this.pageContainer =
            document.createElement("div");

        this.pageContainer.className =
            "mx-auto flex flex-col gap-8";

        this.viewer.appendChild(
            this.pageContainer
        );

        this.container.append(
            this.toolbar,
            this.viewer
        );

    }

    button(text, callback) {

        const button =
            document.createElement("button");

        button.type = "button";

        button.className =
            "rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm transition hover:bg-gray-100";

        button.textContent = text;

        button.addEventListener(
            "click",
            callback
        );

        return button;

    }

    async buildPages() {

        this.pageWrappers = [];

        this.pageContainer.innerHTML = "";

        for (
            let pageNumber = 1;
            pageNumber <= this.totalPages;
            pageNumber++
        ) {

            const page =
                await this.pdf.getPage(pageNumber);

            const viewport =
                page.getViewport({
                    scale: this.zoom
                });

            const wrapper =
                document.createElement("div");

            wrapper.dataset.page =
                pageNumber;

            wrapper.dataset.rendered =
                "false";

            wrapper.className =
                "flex justify-center";

            wrapper.style.height =
                `${viewport.height}px`;

            wrapper.className =
                "mx-auto flex items-center justify-center rounded-xl bg-white shadow-xl";

            this.pageContainer.appendChild(
                wrapper
            );

            this.pageWrappers.push({
                page,
                wrapper,
                viewport
            });

        }

    }

    observePages() {

        if (this.intersectionObserver) {

            this.intersectionObserver.disconnect();

        }

        this.intersectionObserver =
            new IntersectionObserver(
                entries => {

                    entries.forEach(async entry => {

                        const wrapper =
                            entry.target;

                        const pageNumber =
                            Number(wrapper.dataset.page);

                        if (entry.isIntersecting) {

                            this.currentPage =
                                pageNumber;

                            this.updatePageCounter();

                            if (
                                wrapper.dataset.rendered ===
                                "false"
                            ) {

                                wrapper.dataset.rendered =
                                    "true";

                                await this.renderPage(
                                    pageNumber
                                );

                            }

                        }

                    });

                },
                {
                    root: this.viewer,
                    threshold: 0.15
                }
            );

        this.pageWrappers.forEach(page => {

            this.intersectionObserver.observe(
                page.wrapper
            );

        });

    }

        async renderPage(pageNumber) {

        const pageData =
            this.pageWrappers[pageNumber - 1];

        if (!pageData) {
            return;
        }

        const {

            page,

            wrapper

        } = pageData;

        wrapper.innerHTML = "";

        const viewport =
            page.getViewport({
                scale: this.zoom
            });

        pageData.viewport =
            viewport;

        wrapper.className =
            "flex justify-center";

        wrapper.style.height =
            `${viewport.height}px`;

        const canvas =
            document.createElement("canvas");

        canvas.className =
            "block rounded-xl";

        const context =
            canvas.getContext("2d");

        const outputScale =
            window.devicePixelRatio || 1;

        canvas.width =
            Math.floor(
                viewport.width * outputScale
            );

        canvas.height =
            Math.floor(
                viewport.height * outputScale
            );

        canvas.style.width =
            `${viewport.width}px`;

        canvas.style.height =
            `${viewport.height}px`;

        context.setTransform(
            outputScale,
            0,
            0,
            outputScale,
            0,
            0
        );

        await page.render({

            canvasContext: context,

            viewport

        }).promise;

        wrapper.appendChild(canvas);

    }

    async rerenderVisiblePages() {

        for (const pageData of this.pageWrappers) {

            if (
                pageData.wrapper.dataset.rendered ===
                "true"
            ) {

                pageData.wrapper.dataset.rendered =
                    "false";

            }

        }

        const observer =
            this.intersectionObserver;

        observer.disconnect();

        this.observePages();

    }

    async calculateFitWidth() {

        const firstPage =
            await this.pdf.getPage(1);

        const viewport =
            firstPage.getViewport({
                scale: 1
            });

        const availableWidth =
            this.viewer.clientWidth - 64;

        this.fitWidthScale =
            availableWidth /
            viewport.width;

        if (
            this.fitWidthScale >
            this.maxZoom
        ) {

            this.fitWidthScale =
                this.maxZoom;

        }

        if (
            this.fitWidthScale <
            this.minZoom
        ) {

            this.fitWidthScale =
                this.minZoom;

        }

    }

    async zoomIn() {

        if (
            this.zoom >=
            this.maxZoom
        ) {

            return;

        }

        this.zoom =
            Math.min(

                this.maxZoom,

                this.zoom +
                    this.zoomStep

            );

        this.updateZoomLabel();

        await this.rebuildPages();

    }

    async zoomOut() {

        if (
            this.zoom <=
            this.minZoom
        ) {

            return;

        }

        this.zoom =
            Math.max(

                this.minZoom,

                this.zoom -
                    this.zoomStep

            );

        this.updateZoomLabel();

        await this.rebuildPages();

    }

    async fitWidth() {

        await this.calculateFitWidth();

        this.zoom =
            this.fitWidthScale;

        this.updateZoomLabel();

        await this.rebuildPages();

    }

    async rebuildPages() {

        if (
            this.intersectionObserver
        ) {

            this.intersectionObserver.disconnect();

        }

        await this.buildPages();

        this.observePages();

    }

    updateZoomLabel() {

        this.zoomLabel.textContent =
            `${Math.round(
                this.zoom * 100
            )}%`;

    }

    updatePageCounter() {

        this.pageCounter.textContent =
            `Page ${this.currentPage} of ${this.totalPages}`;

    }

    registerEvents() {

        this.viewer.addEventListener(
            "wheel",
            async event => {

                if (
                    !event.ctrlKey
                ) {

                    return;

                }

                event.preventDefault();

                if (
                    event.deltaY < 0
                ) {

                    await this.zoomIn();

                } else {

                    await this.zoomOut();

                }

            },
            {
                passive: false
            }
        );

        window.addEventListener(
            "keydown",
            async event => {

                if (
                    !event.ctrlKey
                ) {

                    return;

                }

                switch (
                    event.key
                ) {

                    case "=":

                    case "+":

                        event.preventDefault();

                        await this.zoomIn();

                        break;

                    case "-":

                        event.preventDefault();

                        await this.zoomOut();

                        break;

                    case "0":

                        event.preventDefault();

                        await this.fitWidth();

                        break;

                }

            }
        );

        window.addEventListener(
            "resize",
            () => {

                clearTimeout(
                    this.resizeTimeout
                );

                this.resizeTimeout =
                    setTimeout(
                        async () => {

                            await this.calculateFitWidth();

                        },
                        250
                    );

            }
        );

    }

}

export async function initializePdfViewer() {

    const container =
        document.getElementById(
            "pdf-viewer"
        );

    if (!container) {

        return;

    }

    const viewer =
        new PdfViewer(
            container
        );

    await viewer.initialize();

}