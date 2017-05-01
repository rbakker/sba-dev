#
# add jsonrpc2 support...
#

import deepzoom

# Specify your source image
SOURCE = "/my/rawdata/bigbrain/pm3500o.png"

# Create Deep Zoom Image creator with weird parameters
creator = deepzoom.ImageCreator(tile_size=254, tile_overlap=1, tile_format="jp2",
                                image_quality=0.8, resize_filter="bicubic")

# Create Deep Zoom image pyramid from source
creator.create(SOURCE, "/my/rawdata/bigbrain/pm3500o.dzi")
