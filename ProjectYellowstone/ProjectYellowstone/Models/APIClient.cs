using Newtonsoft.Json;
using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Threading.Tasks;

namespace ProjectYellowstone.Models
{
	public class APIClient
	{
		private const string baseAPIURL = "http://cs99.dedyn.io:49258";

		private const string authenticationExtension = "/token";
		private const string firesExtension = "/fires";
		private const string searchesExtension = "/searches";

		private const string authEmail = "dontcare@email.com";
		private const string authPass = "DontCare-Aurel";

		private string authenticationToken;

		public async Task<bool> AuthenticateAsync()
		{
			var httpClient = new HttpClient();

			var content = new FormUrlEncodedContent(new[]
			{
				new KeyValuePair<string, string>("email", authEmail),
				new KeyValuePair<string, string>("password", authPass)
			});

			var requestUrl = baseAPIURL + authenticationExtension;

			var authResponse = await httpClient.PostAsync(requestUrl, content);

			if (!authResponse.IsSuccessStatusCode)
				return false;

			var rawResponse = await authResponse.Content.ReadAsStringAsync();

			authenticationToken = JsonConvert.DeserializeObject<APIAuthResponse>(rawResponse).Token;

			return true;
		}

		public async Task<Report[]> GetRadiusReportsAsync(float lat, float lng, float radius)
		{
			var rawData = await GetRawRadiusReportsAsync(lat, lng, radius);

			return JsonConvert.DeserializeObject<Report[]>(rawData);
		}

		public async Task<string> GetRawRadiusReportsAsync(float lat, float lng, float radius)
		{
			if (string.IsNullOrWhiteSpace(authenticationToken))
				throw new InvalidOperationException("Not authenticated.");

			var requestUrl = $"{baseAPIURL}{firesExtension}{searchesExtension}";

			var httpClient = new HttpClient();
			httpClient.DefaultRequestHeaders.Add("Authorization", "BEARER " + authenticationToken);

			var content = new FormUrlEncodedContent(new[]
			{
				new KeyValuePair<string, string>("user_latitude", lat.ToString()),
				new KeyValuePair<string, string>("user_longitude", lng.ToString()),
				new KeyValuePair<string, string>("user_proximity", radius.ToString()),
			});

			var fireResponse = await httpClient.PostAsync(requestUrl, content);

			return await fireResponse.Content.ReadAsStringAsync();
		}

		public async Task<int> SendReport(float lat, float lng)
		{
			if (string.IsNullOrWhiteSpace(authenticationToken))
				throw new InvalidOperationException("Not authenticated");

			var requestUrl = $"{baseAPIURL}{firesExtension}";
			
			var httpClient = new HttpClient();
			httpClient.DefaultRequestHeaders.Add("Authorization", "BEARER " + authenticationToken);

			var content = new FormUrlEncodedContent(new[]
			{
				new KeyValuePair<string, string>("latitude", lat.ToString()),
				new KeyValuePair<string, string>("longitude", lng.ToString())
			});

			var reportResponse = await httpClient.PostAsync(requestUrl, content);

			var rawResponse = await reportResponse.Content.ReadAsStringAsync();

			var reportID = JsonConvert.DeserializeObject<int[]>(rawResponse)[0];

			return reportID;
		}

		public async Task<Report> GetFireAsync(int id)
		{
			var rawReport = await GetRawFireAsync(id);

			return JsonConvert.DeserializeObject<Report>(rawReport);
		}

		public async Task<string> GetRawFireAsync(int id)
		{
			if (string.IsNullOrWhiteSpace(authenticationToken))
				throw new InvalidOperationException("Not authenticated");

			var httpClient = new HttpClient();
			httpClient.DefaultRequestHeaders.Add("Authorization", "BEARER " + authenticationToken);

			var requestUrl = $"{baseAPIURL}{firesExtension}/{id}";

			var fireReportResponse = await httpClient.GetAsync(requestUrl);

			return await fireReportResponse.Content.ReadAsStringAsync();
		}
	}

	public class APIReportResponse
	{
		public int ID { get; set; }
	}

	public class APIAuthResponse
	{
		public string Token { get; set; }
	}

	public class Report
	{
		/// <summary>
		/// Gets or sets the ID
		/// </summary>
		public int ID { get; set; }
		/// <summary>
		/// Gets or set Latitude
		/// </summary>
		public float Latitude { get; set; }

		/// <summary>
		/// Gets or sets Longitude
		/// </summary>
		public float Longitude { get; set; }

		/// <summary>
		/// Gets or the probability that its a fire
		/// </summary>
		public int Confidence { get; set; }

		/// <summary>
		/// Gets or sets the temperature of the fire
		/// </summary>
		public float Temperature { get; set; }

		/// <summary>
		/// Gets or sets UserSubmitted
		/// </summary>
		public bool UserSubmitted { get; set; }

        /// <summary>
        /// Gets or sets the DateAcquired
        /// </summary>
        [JsonProperty(PropertyName = "date_acquired")]
        public string DateAcquired { get; set; }
    }

	public class ReportWrapper
	{
		public IList<Report> Reports { get; set; }
	}
}