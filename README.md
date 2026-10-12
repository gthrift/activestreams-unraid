# Active Streams Widget for Unraid

Active Streams is a lightweight Unraid plugin that adds a real-time dashboard widget to monitor active media streams from **Plex**, **Emby**, **Jellyfin**, and **Dispatcharr** servers directly from your Unraid WebGUI.

Support my work on [PayPal.me](https://www.paypal.me/gthrift385)


## Disclaimer

This project is an unofficial community plugin and is not affiliated with, endorsed by, or associated with Plex®, Emby®, Jellyfin®, or Dispatcharr.

"Plex" is a trademark of Plex, Inc. "Emby" is a trademark of Emby Media Inc. "Jellyfin" is a trademark of the Jellyfin Contributors. All rights reserved.

## Features

* **Multi-Server Support:** Monitor streams from Plex, Emby, Jellyfin, and Dispatcharr (live TV and VOD) servers—all in one widget
* **Multiple Instances:** Configure as many server instances as you need
* **Dashboard Widget:** A native Unraid dashboard tile showing who is watching what, on which device, and playback progress
* **Real-time Progress:** Shows current position and total duration (HH:MM:SS / HH:MM:SS format)
* **Customizable Appearance:** Choose your preferred icon (Plex, Emby, Jellyfin, Dispatcharr, or generic) and widget title
* **Real-time Updates:** Configurable polling interval to keep the dashboard current
* **Transcoding Indicator:** Visual indicator when streams are being transcoded
* **Seamless Integration:** Follows Unraid 6.12+ design standards (collapsible, movable, and theme-compliant)
* **Simple Setup:** Dedicated settings page under "User Utilities" for easy configuration

## Preview

| Dashboard Widget |
| :--- |
| ![Dashboard Example](https://raw.githubusercontent.com/gthrift/activestreams-unraid/main/metadata/widget_preview.png) |



## Installation

1. Log in to your Unraid WebGUI.
2. Navigate to the **Plugins** tab.
3. Click on the **Install Plugin** sub-tab.
4. Copy and paste the following URL into the box:
   ```text
   https://raw.githubusercontent.com/gthrift/activestreams-unraid/main/activestreams.plg
   ```
5. Click **Install**.

## Configuration

After installation, navigate to **Settings > Active Streams Settings** to configure:

### General Settings

- **Widget Title**: Choose from "Active Streams", "Video Streams", "Plex Streams", "Emby Streams", "Jellyfin Streams", "Dispatcharr Streams", or "IPTV Streams"
- **Widget Icon**: Select Plex, Emby, Jellyfin, Dispatcharr, or a generic film icon
- **Refresh Interval**: How often to poll servers (1-60 seconds)

### Adding Media Servers

1. Click **Add Server**
2. Select the server type (Plex, Emby, Jellyfin, or Dispatcharr)
3. Enter a friendly display name
4. Enter the server's IP address/hostname and port
5. Enter your API token/key:
   - **Plex**: Find your X-Plex-Token in browser developer tools or use [this guide](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/)
   - **Emby**: Generate in Dashboard > Advanced > API Keys
   - **Jellyfin**: Generate in Dashboard > Advanced > API Keys
   - **Dispatcharr**: Generate in your user settings > API & XC (default port 9191)
6. Check "Use HTTPS/SSL" if your server uses SSL
7. Click **Test Connection** to verify settings
8. Click **Save Server**

## Getting Your API Token/Key

### Plex (X-Plex-Token)

1. Open Plex Web App in your browser
2. Open browser Developer Tools (F12)
3. Go to Network tab
4. Browse to any media item
5. Look for requests containing `X-Plex-Token=` in the URL
6. Copy the token value

### Emby

1. Open Emby Dashboard
2. Navigate to Advanced > API Keys
3. Click the **+** button to create a new key
4. Give it a name (e.g., "Unraid Widget")
5. Copy the generated key

### Jellyfin

1. Open Jellyfin Dashboard
2. Navigate to Advanced > API Keys
3. Click the **+** button to create a new key
4. Give it a name (e.g., "Unraid Widget")
5. Copy the generated key

### Dispatcharr

Requires Dispatcharr v0.20.0 or later. Live and VOD streams are shown on v0.28.0 and later; older versions show live channels only.

1. Log in to Dispatcharr as an **admin** user (stream stats are admin-only)
2. Open your user settings and go to the **API & XC** tab
3. Generate an API key
4. Copy the generated key

## Displayed Information

For each active stream, the widget shows:

- **Server Indicator**: Colored dot indicating which server (orange=Plex, green=Emby, blue=Jellyfin, teal=Dispatcharr)
- **Title**: Media name (includes series name for TV shows)
- **Device**: Client device/app name
- **User**: Username watching
- **Progress**: Current position / Total duration (with play/pause icon). Dispatcharr live channels show time watched and "Live"
- **Transcoding**: Exchange icon appears when transcoding. Dispatcharr rows show an info icon with source stream details

## Troubleshooting

### Connection Failed
- Verify the server IP and port are correct
- Ensure the API token/key is valid
- Check if the server is running and accessible from Unraid
- If using SSL, verify the certificate or try disabling SSL
- **Dispatcharr HTTP 403**: The API key must belong to an admin user

### No Streams Showing
- Confirm someone is actively playing media
- Increase the refresh interval if seeing rate limiting
- Check server logs for any API errors

### Widget Not Appearing
- Ensure you have the latest version installed

## License

MIT License - See LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests on GitHub.

## Support

If you encounter any issues or have feature requests, please open an issue on the GitHub repository.
