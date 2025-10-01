(function (OCA) {
	if (!OCA.Viewer) {
		console.warn('Viewer not available')
		return
	}

	const RAWViewer = {
		name: 'RAWViewer',
		props: {
			filename: { type: String, default: null },
			fileid: { type: Number, default: null },
			previewPath: { type: String, default: null },
		},
		render(createElement) {
			if (!this.previewPath) {
				return createElement('div', 'Kein Preview verfügbar')
			}
			const url = OC.generateUrl(this.previewPath)
			return createElement('img', {
				attrs: {
					src: url,
					alt: this.filename || 'RAW preview',
					style: 'max-width: 100%; max-height: 100%; object-fit: contain;',
				},
				on: {
					load: () => {
						this.doneLoading()
					}
				}
			})
		},
	}

	OCA.Viewer.registerHandler({
		id: 'camerarawpreviews',
		group: 'media',
		mimes: [
			'image/x-dcraw',
		],
		component: RAWViewer,
	})

	console.log('RAWViewer registered!')
})(OCA)
